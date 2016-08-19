# MageSail 
##### Sailthru Magento 2 Extension
----------------------

## Installation Instructions

1. Get the module
	via composer  `composer require sailthru/sailthru-magento2-extension`

2. Enable the module
    `bin/magento module:enable Sailthru_Magesail`

3. Go to Magento Admin > Stores > Configuration > Sailthru to configure. Visit the [Sailthru Documentation Site][1] for setup documentation.

*__Note__: If sync'ing variant products with no visible individual URL, you should enable "Preserve Fragments" in Sailthru [here][2].*

## SPM Setup
The Sailthru MageSail module comes ready to use Sailthru's new PersonalizeJs javascript.
 - To add page-tracking and gather associated data, set "Site Domain" [here][3].
 - To use Site Personalization Manager, please contact Sailthru.


[1]: https://getstarted.sailthru.com/integrations/overview/
[2]: https://my.sailthru.com/settings/spider
[3]: https://my.sailthru.com/settings/domains