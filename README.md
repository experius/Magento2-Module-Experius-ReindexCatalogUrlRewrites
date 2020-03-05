# DEPRECATED

This module has been deprecated.

Please use the following recommended alternative:


https://github.com/elgentos/regenerate-catalog-urls


It is possible that the module has created invalid category url rewrites because the rewrites are created for all the stores and not for the stores which are related to the category. The recommended alternative uses the stores related to the categories.

To see if you have invalid url rewrites run the following select query:

```
SELECT DISTINCT u.url_rewrite_id from store as s 
INNER JOIN store_group as sg
  ON s.group_id = sg.group_id 
INNER JOIN catalog_category_entity as c
  ON sg.root_category_id = SUBSTRING_INDEX(SUBSTRING_INDEX(c.path, '/', 2), '/', -1)
LEFT JOIN url_rewrite as u
  ON c.entity_id = u.entity_id AND s.store_id != u.store_id
WHERE entity_type = 'category';
```

This query will return url rewrite ids which you can then remove from the url_rewrite table.

----

----

 

**Warnings**

- BETA
- Don't ever use without testing on dev or staging enviroment first. 

**Usage**

All Categories, All Products, All Storeviews
```php bin/magento experius_reindexcatalogurlrewrites:categoryurls```

Specific Products, Specific Store Ids

```php bin/magento experius_reindexcatalogurlrewrites:producturls --product_ids=36,37 --store_ids=1```

All Products, All Storeviews

```php bin/magento experius_reindexcatalogurlrewrites:producturls ```

All Products, Selected Storeviews

```php bin/magento experius_reindexcatalogurlrewrites:producturls --store_ids=1 ```

All Storeviews, Selected Products

```php bin/magento experius_reindexcatalogurlrewrites:producturls --product_ids=36,37 ```


**Credits**

https://github.com/Iazel/magento2-regenurl/

@PascalBrouwers categories url pull request
