# Measurements

Each script here produced a number that is quoted in the documentation or in a commit
message. Keeping the script is what makes the number re-takeable rather than remembered.

| Script | What it answers |
|---|---|
| `fanout_rate_limiter.php` | How many requests a limit of five admits when they arrive together |

Run them with the same binary the suite uses:

```
php dev/measurements/fanout_rate_limiter.php
```
