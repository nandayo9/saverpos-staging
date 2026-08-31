<?php

namespace Modules\Recommerce\Services;

use App\Contact;
use App\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\TradeInSellerRepresentation;

/** Keeps the staff-facing seller identity separate from UltimatePOS's purchase payee representation. */
class TradeInSellerService
{
    public function resolveSupplierRepresentation(User $user, int $customerContactId, ?string $phoneSnapshot = null): Contact
    {
        return DB::transaction(function () use ($user, $customerContactId, $phoneSnapshot): Contact {
            $customer = Contact::query()->where('business_id', $user->business_id)->whereKey($customerContactId)
                ->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $customer) {
                throw new LogicException('Seller must be an active customer in this business.');
            }
            if ($customer->type === 'both') {
                return $customer;
            }
            $link = TradeInSellerRepresentation::query()->where('business_id', $user->business_id)
                ->where('customer_contact_id', $customer->id)->lockForUpdate()->first();
            if ($link) {
                $supplier = Contact::query()->where('business_id', $user->business_id)->whereKey($link->supplier_contact_id)
                    ->whereIn('type', ['supplier', 'both'])->whereNull('deleted_at')->first();
                if ($supplier) {
                    return $supplier;
                }
            }
            $phone = trim((string) ($phoneSnapshot ?: $customer->mobile));
            if ($phone === '') {
                throw new LogicException('Seller phone number is required before a native purchase payee can be created.');
            }
            $supplier = Contact::create([
                'business_id' => $user->business_id,
                'type' => 'supplier',
                'name' => $customer->name,
                'mobile' => $phone,
                'created_by' => $user->id,
                'is_default' => false,
            ]);
            TradeInSellerRepresentation::create([
                'business_id' => $user->business_id,
                'customer_contact_id' => $customer->id,
                'supplier_contact_id' => $supplier->id,
                'created_by' => $user->id,
            ]);

            return $supplier;
        });
    }

    public function resolveOrCreateCustomer(User $user, ?int $customerContactId, ?string $name, ?string $phone): Contact
    {
        if ($customerContactId) {
            $customer = Contact::query()->where('business_id', $user->business_id)->whereKey($customerContactId)
                ->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->first();
            if ($customer) {
                return $customer;
            }
            throw new LogicException('Selected seller is not an active customer in this business.');
        }
        if (! $user->can('customer.create')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Create-customer permission is required to register a new seller.');
        }
        $name = trim((string) $name);
        $phone = trim((string) $phone);
        if ($name === '' || $phone === '') {
            throw new LogicException('Enter a seller name and phone number to create a customer.');
        }

        return Contact::create([
            'business_id' => $user->business_id, 'type' => 'customer', 'name' => mb_substr($name, 0, 255),
            'mobile' => mb_substr($phone, 0, 80), 'created_by' => $user->id, 'is_default' => false,
        ]);
    }
}
