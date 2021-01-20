# TYPO3 Extension cli\_browser

This extension provides a simple way of browsing through DB records via the CLI.

## Commands

### Browsing CEs

```
$ bin/typo3 database:browse-contents --help

Options:
      --CType=CTYPE          What CType are you looking for?
      --list_type=LIST_TYPE  What list_type are you looking for?
      --url                  Render the URL?
      --site                 Render the site?
      --with-deleted         Include deleted records?
      --without-hidden       Include hidden records?
      --without-past         Include past records?
      --without-future       Include future records?
      --limit=LIMIT          How many records? [default: 5]
      --columns[=COLUMNS]    Which columns should be display?
```

### Browsing records

```
$ bin/typo3 database:browse-records --help

Options:
      --table=TABLE        What table are you looking for?
      --type[=TYPE]        What type are you looking for?
      --site               Render the site?
      --group-by-pid       Group by pid?
      --with-deleted       Include deleted records?
      --without-hidden     Include hidden records?
      --without-past       Include past records?
      --without-future     Include future records?
      --limit=LIMIT        How many records? [default: 5]
      --columns[=COLUMNS]  Which columns should be display?
```

## Examples

### Example 1: find 5 news plugins (incl. URLs)

```
bin/typo3 database:browse-contents --CType list --list_type news_pi1 --limit 5 --url
```

### Example 2: find 5 news records (incl. site identifier)

```
bin/typo3 database:browse-records --table tx_news_domain_model_news --limit 5 --site
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
