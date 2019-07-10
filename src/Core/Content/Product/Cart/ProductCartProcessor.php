<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\Exception\MissingLineItemPriceException;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\QuantityInformation;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Price\ProductPriceDefinitionBuilderInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ProductCartProcessor implements CartProcessorInterface, CartDataCollectorInterface
{
    /**
     * @var ProductGatewayInterface
     */
    private $productGateway;

    /**
     * @var ProductPriceDefinitionBuilderInterface
     */
    private $priceDefinitionBuilder;

    /**
     * @var QuantityPriceCalculator
     */
    private $calculator;

    public function __construct(
        ProductGatewayInterface $productGateway,
        QuantityPriceCalculator $calculator,
        ProductPriceDefinitionBuilderInterface $priceDefinitionBuilder
    ) {
        $this->productGateway = $productGateway;
        $this->priceDefinitionBuilder = $priceDefinitionBuilder;
        $this->calculator = $calculator;
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $lineItems = $original
            ->getLineItems()
            ->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        // find products in original cart which requires data from gateway
        $ids = $this->getNotCompleted($data, $lineItems);

        if (!empty($ids)) {
            // fetch missing data over gateway
            $products = $this->productGateway->get($ids, $context);

            // add products to data collection
            foreach ($products as $product) {
                $data->set('product-' . $product->getId(), $product);
            }
        }

        foreach ($lineItems as $lineItem) {
            // enrich all products in original cart
            $this->enrich($lineItem, $data, $context, $behavior);
        }
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // handle all products which stored in root level
        $lineItems = $original
            ->getLineItems()
            ->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        foreach ($lineItems as $lineItem) {
            $definition = $lineItem->getPriceDefinition();

            if (!$definition instanceof QuantityPriceDefinition) {
                throw new MissingLineItemPriceException($lineItem->getId());
            }

            if (!$behavior->isRecalculation()) {
                $product = $data->get('product-' . $lineItem->getReferencedId());

                $available = $this->getAvailableStock($product, $lineItem);

                /** @var ProductEntity $product */
                if ($available <= 0 || $available < $product->getMinPurchase()) {
                    $original->remove($lineItem->getId());

                    $original->addErrors(
                        new ProductOutOfStockError($product->getId(), (string) $product->getTranslation('name'))
                    );

                    continue;
                }

                if ($available < $lineItem->getQuantity()) {
                    $lineItem->setQuantity($available);

                    $definition->setQuantity($available);

                    $toCalculate->addErrors(
                        new ProductStockReachedError($product->getId(), (string) $product->getTranslation('name'), $available)
                    );
                }
            }

            $lineItem->setPrice(
                $this->calculator->calculate($definition, $context)
            );

            // move enriched items to cart,
            // we could calculate the price here but the \Shopware\Core\Checkout\Cart\Calculator will calculate all items after each processor automatically
            $toCalculate->add($lineItem);
        }
    }

    private function enrich(
        LineItem $lineItem,
        CartDataCollection $data,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $id = $lineItem->getReferencedId();

        $key = 'product-' . $id;

        $product = $data->get($key);

        /* @var ProductEntity $product */
        if (!$product || !$product instanceof ProductEntity) {
            throw new ProductNotFoundException($id);
        }

        // already enriched and not modified? Skip
        if ($this->isComplete($lineItem) && !$lineItem->isModified()) {
            return;
        }

        $lineItem->setLabel($product->getTranslation('name'));

        if ($product->getCover()) {
            $lineItem->setCover($product->getCover()->getMedia());
        }

        /* @var ProductEntity $product */
        $deliveryTime = null;
        if ($product->getDeliveryTime()) {
            $deliveryTime = DeliveryTime::createFromEntity($product->getDeliveryTime());
        }

        $lineItem->setDeliveryInformation(
            new DeliveryInformation(
                (int) $product->getAvailableStock(),
                (float) $product->getWeight(),
                $product->getShippingFree(),
                $product->getRestockTime(),
                $deliveryTime
            )
        );

        // check if the price has to be updated
        if (!$this->isPriceComplete($lineItem, $behavior)) {
            $prices = $this->priceDefinitionBuilder->build($product, $context, $lineItem->getQuantity());

            $lineItem->setPriceDefinition($prices->getQuantityPrice());
        }

        $quantityInformation = new QuantityInformation();

        if ($product->getMinPurchase() > 0) {
            $quantityInformation->setMinPurchase($product->getMinPurchase());
        }

        if ($product->getIsCloseout()) {
            $max = $product->getAvailableStock();

            if ($product->getMaxPurchase() > 0 && $product->getMaxPurchase() < $max) {
                $max = $product->getMaxPurchase();
            }

            $quantityInformation->setMaxPurchase($max);
        }

        if ($product->getPurchaseSteps() > 0) {
            $quantityInformation->setPurchaseSteps($product->getPurchaseSteps());
        }

        $lineItem->setQuantityInformation($quantityInformation);

        $lineItem->replacePayload([
            'tags' => $product->getTagIds(),
            'categories' => $product->getCategoryTree(),
            'properties' => $product->getPropertyIds(),
            'productNumber' => $product->getProductNumber(),
        ]);

        if ($product->hasExtension('canonicalUrl')) {
            $lineItem->addExtension('canonicalUrl', $product->getExtension('canonicalUrl'));
        }
    }

    private function getNotCompleted(CartDataCollection $data, array $lineItems): array
    {
        $ids = [];

        /** @var LineItem $lineItem */
        foreach ($lineItems as $lineItem) {
            $id = $lineItem->getReferencedId();

            $key = 'product-' . $id;

            // data already fetched?
            if ($data->has($key)) {
                continue;
            }

            // user change line item quantity or price?
            if ($lineItem->isModified()) {
                $ids[] = $id;
                continue;
            }

            // already enriched?
            if ($this->isComplete($lineItem)) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function isComplete(LineItem $lineItem): bool
    {
        return $lineItem->getPriceDefinition() !== null
            && $lineItem->getLabel() !== null
            && $lineItem->getCover() !== null
            && $lineItem->getDescription() !== null
            && $lineItem->getDeliveryInformation() !== null
            && $lineItem->getQuantityInformation() !== null;
    }

    private function isPriceComplete(LineItem $lineItem, CartBehavior $behavior): bool
    {
        //always update prices for live checkout
        if (!$behavior->isRecalculation()) {
            return false;
        }

        return $lineItem->getPriceDefinition() !== null;
    }

    private function getAvailableStock(ProductEntity $product, LineItem $lineItem): int
    {
        if (!$product->getIsCloseout()) {
            return $lineItem->getQuantity();
        }

        return $product->getAvailableStock();
    }

    private function validateStock(Cart $toCalculate, LineItem $lineItem, ProductEntity $product, $definition): void
    {
    }
}
