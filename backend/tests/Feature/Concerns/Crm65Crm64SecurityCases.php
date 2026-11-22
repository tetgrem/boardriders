<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Services\Crm64\Crm64Access;
use App\Services\Crm64\Crm64Card;
use App\Services\Crm64\Crm64Notes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

trait Crm65Crm64SecurityCases
{
    public function test_cod_crm65_support_cannot_write_finance_note(): void
    {
        $order = $this->paidOrder();
        $this->actor(6404);
        $this->expectException(AuthorizationException::class);
        app(Crm64Notes::class)->create(
            $order['order_key'], (string) Str::uuid(),
            'finance', 'Confidential payout details'
        );
    }

    public function test_cod_crm65_finance_notes_are_hidden_from_basic_viewer(): void
    {
        $order = $this->paidOrder();
        Gate::define(Crm64Access::NOTES, static fn ($user): bool =>
            in_array((int) $user->getAuthIdentifier(), [6402, 6404], true)
        );
        $this->actor(6402);
        app(Crm64Notes::class)->create(
            $order['order_key'], (string) Str::uuid(),
            'finance', 'Sensitive finance-only note'
        );
        $this->actor(6401);
        $card = app(Crm64Card::class)->show($order['order_key']);
        $this->assertSame([], $card['notes']);
        $this->assertSame([], array_values(array_filter(
            $card['timeline']['items'],
            static fn (array $event): bool => $event['source'] === 'crm_note'
        )));
    }

    public function test_cod_crm65_fiscal_notes_are_hidden_from_basic_viewer(): void
    {
        $order = $this->paidOrder();
        Gate::define(Crm64Access::NOTES, static fn ($user): bool =>
            in_array((int) $user->getAuthIdentifier(), [6403, 6404], true)
        );
        $this->actor(6403);
        app(Crm64Notes::class)->create(
            $order['order_key'], (string) Str::uuid(),
            'fiscal', 'Restricted fiscal provider details'
        );
        $this->actor(6401);
        $card = app(Crm64Card::class)->show($order['order_key']);
        $this->assertSame([], $card['notes']);
    }
}
