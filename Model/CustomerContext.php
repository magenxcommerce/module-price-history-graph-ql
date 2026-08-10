<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\PriceHistoryGraphQl\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;

/**
 * Resolves the customer group id for the current GraphQL request.
 *
 * Prices (special price / catalog price rules / tier prices) are
 * per-customer-group, so the prior-price lookup must use the same group the
 * storefront prices were shown for: the logged-in customer's group, or
 * NOT_LOGGED_IN (0) for guests. Cached per request.
 */
class CustomerContext
{
    /** @var array<int, int> customer id => group id */
    private array $groupCache = [];

    /**
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * The customer group id for the request.
     *
     * @param ContextInterface $context
     * @return int
     */
    public function getGroupId(ContextInterface $context): int
    {
        $userId = (int) $context->getUserId();
        if ($context->getExtensionAttributes()->getIsCustomer() === true && $userId > 0) {
            if (isset($this->groupCache[$userId])) {
                return $this->groupCache[$userId];
            }
            try {
                return $this->groupCache[$userId] =
                    (int) $this->customerRepository->getById($userId)->getGroupId();
            } catch (\Exception $e) {
                return GroupInterface::NOT_LOGGED_IN_ID;
            }
        }

        return GroupInterface::NOT_LOGGED_IN_ID;
    }
}
