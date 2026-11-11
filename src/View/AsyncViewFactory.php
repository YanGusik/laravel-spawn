<?php

namespace Spawn\Laravel\View;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\ViewFinderInterface;
use Spawn\Laravel\Foundation\RequestContext;

use function Async\root_context;

/**
 * A view factory whose render state belongs to the request instead of the worker.
 *
 * There is one factory per worker, as upstream intends: templates receive it as `$__env`,
 * `Component::$factory` caches it in a static, and `MailManager` and `Markdown` keep it in
 * their constructors. What moves per request is the state it writes while rendering —
 * `@section`, `@push`, the component and slot stacks, the nesting counter and the `@once`
 * ledger — because a render suspends whenever it touches the world: a composer queries the
 * database, a template reads a lazy relation, a template is compiled for the first time.
 * Shared, two responses write into one set of sections, and the nesting counter of one
 * render reaching zero empties the sections of the other in the middle of a page.
 *
 * The state moves without touching the framework methods that write it, of which there are
 * about fifty. The declared properties are removed from this object in the constructor, so
 * every read and write the inherited traits make falls through to `__get()` and `__set()`
 * and lands in a {@see BladeRenderState} taken from the request's context. `__get()`
 * returns by reference, which is what lets unmodified code run
 * `array_pop($this->sectionStack)` and `$this->pushes[$section][$key] .= $content`.
 *
 * Before bootCompleted(), and outside a request, one state held here answers instead: an
 * error page rendered during bootstrap, an artisan command and a test all keep working,
 * and nothing per-request is stored where the next request would inherit it.
 */
class AsyncViewFactory extends Factory
{
    /**
     * The properties of Factory and its concerns that belong to one render.
     *
     * Checked against the framework by ViewRenderStateTest, which fails when an upgrade
     * adds a property that is in neither this list nor its list of configuration.
     */
    private const RENDER_STATE = [
        'sections',
        'sectionStack',
        'pushes',
        'prepends',
        'pushStack',
        'componentStack',
        'componentData',
        'currentComponentData',
        'slots',
        'slotStack',
        'fragments',
        'fragmentStack',
        'loopsStack',
        'translationReplacements',
        'renderCount',
        'renderedOnce',
    ];

    private const CTX_KEY = 'view.shared';

    private const RENDER_STATE_KEY = 'view.render-state';

    private bool $async = false;

    /**
     * The render state used while there is no request to attach one to.
     */
    private BladeRenderState $processState;

    /**
     * The context the last render state came from, held so that its object handle cannot
     * be handed to another context, and the state that belongs to it.
     */
    private ?\Async\Context $memoContext = null;

    private ?BladeRenderState $memoState = null;

    public function __construct(EngineResolver $engines, ViewFinderInterface $finder, Dispatcher $events)
    {
        $this->processState = new BladeRenderState();

        parent::__construct($engines, $finder, $events);

        foreach (self::RENDER_STATE as $property) {
            unset($this->$property);
        }
    }

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    /**
     * @return mixed a reference into this request's render state, so that the inherited
     *   trait methods can modify its arrays and counters in place
     */
    public function &__get(string $name)
    {
        $state = $this->renderState();

        return $state->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->renderState()->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->renderState()->$name);
    }

    public function __unset(string $name): void
    {
        unset($this->renderState()->$name);
    }

    /*
     * The eight methods below are the framework's own, with one change each: the array is
     * fetched into a local before `isset()` asks about a key in it.
     *
     * `isset($this->sections[$name])` reads a dimension of a property this object does not
     * have, so the engine goes through `__isset()` and `__get()`. Under the tracing JIT
     * that path answers false for a key the array holds — measured at four pages in sixty
     * losing an `@once` block, and the same shape decides `@push`, `@prepend` and every
     * section (true-async/php-async#223). With the array in hand the answer is always
     * right. Everything else in these methods is upstream's, so a change there has to be
     * copied here; ViewRenderStateTest guards the properties, not the methods.
     */

    public function hasRenderedOnce(string $id)
    {
        $renderedOnce = $this->renderedOnce;

        return isset($renderedOnce[$id]);
    }

    public function appendSection()
    {
        if (empty($this->sectionStack)) {
            throw new \InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last     = array_pop($this->sectionStack);
        $sections = $this->sections;

        if (isset($sections[$last])) {
            $this->sections[$last] .= ob_get_clean();
        } else {
            $this->sections[$last] = ob_get_clean();
        }

        return $last;
    }

    protected function extendSection($section, $content)
    {
        $sections = $this->sections;

        if (isset($sections[$section])) {
            $content = str_replace(static::parentPlaceholder($section), $content, $sections[$section]);
        }

        $this->sections[$section] = $content;
    }

    public function yieldContent($section, $default = '')
    {
        $sections       = $this->sections;
        $sectionContent = $default instanceof \Illuminate\View\View ? $default : e($default);

        if (isset($sections[$section])) {
            $sectionContent = $sections[$section];
        }

        $sectionContent = str_replace('@@parent', '--parent--holder--', $sectionContent);

        return str_replace(
            '--parent--holder--', '@parent', str_replace(static::parentPlaceholder($section), '', $sectionContent)
        );
    }

    protected function extendPush($section, $content)
    {
        $pushes = $this->pushes;

        if (! isset($pushes[$section])) {
            $this->pushes[$section] = [];
            $pushes[$section]       = [];
        }

        if (! isset($pushes[$section][$this->renderCount])) {
            $this->pushes[$section][$this->renderCount] = $content;
        } else {
            $this->pushes[$section][$this->renderCount] .= $content;
        }
    }

    protected function extendPrepend($section, $content)
    {
        $prepends = $this->prepends;

        if (! isset($prepends[$section])) {
            $this->prepends[$section] = [];
            $prepends[$section]       = [];
        }

        if (! isset($prepends[$section][$this->renderCount])) {
            $this->prepends[$section][$this->renderCount] = $content;
        } else {
            $this->prepends[$section][$this->renderCount] =
                $content . $prepends[$section][$this->renderCount];
        }
    }

    public function yieldPushContent($section, $default = '')
    {
        if ($this->isStackEmpty($section)) {
            return $default;
        }

        $output   = '';
        $prepends = $this->prepends;
        $pushes   = $this->pushes;

        if (isset($prepends[$section])) {
            $output .= implode(array_reverse($prepends[$section]));
        }

        if (isset($pushes[$section])) {
            $output .= implode($pushes[$section]);
        }

        return $output;
    }

    public function isStackEmpty(string $section): bool
    {
        $pushes   = $this->pushes;
        $prepends = $this->prepends;

        return ! isset($pushes[$section]) && ! isset($prepends[$section]);
    }

    /**
     * Share data with every view of this request, or of the worker before there are any.
     *
     * Before bootCompleted() the data joins the boot-time set the whole worker reads;
     * after it, an overlay in the request's context, which no other request sees.
     */
    public function share($key, $value = null)
    {
        if (! $this->async) {
            return parent::share($key, $value);
        }

        $keys = is_array($key) ? $key : [$key => $value];

        $ctx = RequestContext::current();
        $shared = $ctx->find(self::CTX_KEY);

        if ($shared === null) {
            $ctx->set(self::CTX_KEY, $keys);
        } else {
            foreach ($keys as $k => $v) {
                $shared[$k] = $v;
            }
            $ctx->set(self::CTX_KEY, $shared, replace: true);
        }

        return $value;
    }

    /**
     * One shared value as this request sees it: its own overlay first, then the boot-time set.
     *
     * @param  string  $key  a dot path, as Factory::shared() takes it
     */
    public function shared($key, $default = null)
    {
        if (! $this->async) {
            return parent::shared($key, $default);
        }

        return Arr::get($this->getShared(), $key, $default);
    }

    /**
     * The boot-time shared data with this request's overlay on top of it.
     */
    public function getShared()
    {
        if (! $this->async) {
            return parent::getShared();
        }

        $perRequest = RequestContext::current()->find(self::CTX_KEY) ?? [];

        return array_merge($this->shared, $perRequest);
    }

    /**
     * The render state of the request being served, or the process-wide one.
     *
     * Every read and write of the sixteen moved properties arrives here, which a
     * `@foreach` does on every iteration, so the answer for the last context is kept.
     * At 500 rows a page the lookup cost 225 of the 785 microseconds a render took, and
     * 98 of 668 with the answer kept: 15 per cent off the render
     * (tests/bench/bench_render.php, release build, median of eleven runs).
     *
     * Keeping the context object is what makes comparing it by identity safe. PHP hands
     * a freed object's handle to the next one, so a context released between requests
     * could otherwise come back as the same `===` and answer request B with the state of
     * request A; a context this factory still holds is never freed.
     */
    private function renderState(): BladeRenderState
    {
        if (! $this->async) {
            return $this->processState;
        }

        $context = RequestContext::current();

        if ($context === $this->memoContext) {
            return $this->memoState;
        }

        if ($context === root_context()) {
            return $this->processState;
        }

        $state = $context->find(self::RENDER_STATE_KEY);

        if (! $state instanceof BladeRenderState) {
            $state = new BladeRenderState();

            $context->set(self::RENDER_STATE_KEY, $state);
        }

        $this->memoContext = $context;
        $this->memoState   = $state;

        return $state;
    }
}
