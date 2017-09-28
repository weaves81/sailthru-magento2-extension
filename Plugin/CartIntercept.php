<?php

namespace Sailthru\MageSail\Plugin;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Checkout\Model\Cart;
use Magento\ConfigurableProduct\Model\ConfigurableAttributeData;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Swatches\Block\Product\Renderer\Configurable as SwatchModel;
use Sailthru\MageSail\Helper\ClientManager as ClientManager;
use Sailthru\MageSail\Helper\Settings as SailthruSettings;
use Sailthru\MageSail\Cookie\Hid as SailthruCookie;

class CartIntercept
{

    public function __construct(
        ClientManager $clientManager,
        SailthruSettings $sailthruSettings,
        SailthruCookie $sailthruCookie,
        ProductRepositoryInterface $productRepo,
        Image $imageHelper,
        Config $mediaConfig,
        ProductHelper $productHelper,
        ConfigurableAttributeData $cpData,
        Configurable $configProduct,
        SwatchModel $swatchModel
    ) {
        $this->client = $clientManager;
        $this->sailthruSettings = $sailthruSettings;
        $this->sailthruCookie = $sailthruCookie;
        $this->productRepo = $productRepo;
        $this->imageHelper = $imageHelper;
        $this->mediaConfig = $mediaConfig;
        $this->productHelper = $productHelper;
        $this->cpData = $cpData;
        $this->cpModel = $configProduct;
        $this->swatchModel = $swatchModel;
    }

    public function _gate(Cart $cart)
    {
        $storeId = $cart->getQuote()->getStoreId();
        $this->client = $this->client->getClient(true, $storeId);
        if ($this->sailthruSettings->isAbandonedCartEnabled($storeId)) {
            return $this->sendCart($cart);
        } else {
            return $cart;
        }
    }

    public function sendCart(Cart $cart)
    {
        $customer = $cart->getCustomerSession()->getCustomer();
        $storeId = $cart->getQuote()->getStoreId();
        $email = $customer->getEmail();
        if ($email || $anonymousEmail = $this->isAnonymousReady($storeId)) {
            $email = $email ? $email : $anonymousEmail;
            try {
                $this->client->_eventType = "CartUpdate";
                $items = $this->_getItems($cart);
                $data = [
                    'email'             => $email,
                    'items'             => $items,
                    'incomplete'        => 1,
                    'reminder_time'     => $this->sailthruSettings->getAbandonedTime($storeId),
                    'reminder_template' => $this->sailthruSettings->getAbandonedTemplate($storeId),
                    'message_id'        => $this->sailthruCookie->getBid(),
                ];
                $this->client->apiPost("purchase", $data);
            } catch (\Sailthru_Client_Exception $e) {
                $this->client->logger($e);
            } catch (\Exception $e) {
                $this->client->logger($e);
                throw $e;
            } finally {
                return $cart;
            }
        }
        return $cart;
    }

    public function afterAddProductsByIds(Cart $cardModel, $cart)
    {
        return $this->_gate($cart);
    }

    public function afterAddProduct(Cart $cartModel, $cart)
    {
        return $this->_gate($cart);
    }

    public function afterRemoveItem(Cart $cartModel, $cart)
    {
        return $this->_gate($cart);
    }

    public function afterTruncate(Cart $cardModel, $cart)
    {
        return $this->_gate($cart);
    }

    public function afterUpdateItems(Cart $cardModel, $cart)
    {
        return $this->_gate($cart);
    }

    public function isAnonymousReady($storeId = null)
    {
        if ($this->sailthruSettings->canAbandonAnonymous($storeId) && $hid = $this->sailthruCookie->get()) {
            $response = $this->client->getUserByKey($hid, 'cookie', ['keys' => 1]);
            if (array_key_exists("keys", $response)) {
                $email = $response["keys"]["email"];
                return $email;
            }
        }
        return false;
    }

    /**
     * Prepare data on items in cart or order.
     *
     * @param  Cart $cart
     * 
     * @return array|false
     */
    public function _getItems(Cart $cart)
    {
        $items = $cart->getQuote()->getAllVisibleItems();
        try {
            $data = [];
            $configurableSkus = [];
            foreach ($items as $item) {
                $product = $item->getProduct();
                $_item = [];
                $_item['vars'] = [];
                if ($item->getProduct()->getTypeId() == 'configurable') {
                    $_item['isConfiguration'] = 1;
                    $parentIds[] = $item->getParentItemId();
                    $options = $this->cpModel->getOrderOptions($product);
                    $_item['id'] = $options['simple_sku'];
                    $_item['title'] = $options['simple_name'];
                    $_item['vars'] = $this->_getVars($options);
                    $configurableSkus[] = $options['simple_sku'];
                } elseif (!in_array($item->getSku(), $configurableSkus) && $item->getProductType() != 'bundle') {
                    $_item['id'] = $item->getSku();
                    $_item['title'] = $item->getName();
                } else {
                    $_item['id'] = null;
                }
                if ($_item['id']) {
                    $_item['qty'] = (int) $item->getQty();
                    $_item['url'] = $item->getProduct()->getProductUrl();
                    $_item['image']=$this->productHelper->getSmallImageUrl($product);
                    $current_price = null;
                    $price_used = "reg";
                    $reg_price = $product->getPrice();
                    $special_price = $product->getSpecialPrice();
                    $special_from = $product->getSpecialFromDate();
                    $special_to = $product->getSpecialToDate();
                    if ($special_price &&
                        ($special_from === null || (strtotime($special_from) < strtotime("Today"))) &&
                        ($special_to === null || (strtotime($special_to) > strtotime("Today")))) {
                        $current_price = $special_price;
                        $price_used = "special";
                    } else {
                        $current_price = $reg_price;
                    }
                    $_item['price'] = $current_price * 100;
                    if ($tags = $this->_getTags($product)) {
                        $_item['tags'] = $tags;
                    }
                    $data[] = $_item;
                }
            }
            return $data;
        } catch (\Exception $e) {
            $this->client->logger($e);
            return false;
        }
    }

    /**
     * Get product meta keywords
     * @param Product $product
     * @return string
     */
    public function _getTags(Product $product)
    {
        return $product->getData('meta_keyword');
    }
    
    /**
     *
     * @param array $options
     * @return array
     */
    public function _getVars($options)
    {
        $vars = [];
        $data = $options['attributes_info'];
        foreach ($data as $attribute) {
            $vars[$attribute['label']] = $attribute['value'];
        }
        return $vars;
    }
}
