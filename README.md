# TYPO3 Extension cli\_browser

This extension provides a simple way of browsing through DB records via the CLI.

## Examples

### Example 1: find 5 news plugins

```
bin/typo3 database:browse-contents --CType list --list_type news_pi1 --limit 5
```

### Example 2: find 5 news records

```
bin/typo3 database:browse-records --table tx_news_domain_model_news --limit 5
```

### Example 3: find 5 news records (incl. deleted)

In case you want to extend your search to deleted records use the option:

* `--with-deleted`

```
bin/typo3 database:browse-records --table tx_news_domain_model_news --limit 5 --with-deleted
```

### Example 4: find 5 news records (without hidden/future/past)

In case you want to exclude hidden or timed records from your search use the follwing options:

* `--without-hidden`
* `--without-future`
* `--without-past`

```
bin/typo3 database:browse-records --table tx_news_domain_model_news --limit 5 --without-hidden --without-future --without-past
```
