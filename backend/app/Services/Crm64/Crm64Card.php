<?php

declare(strict_types=1);

namespace App\Services\Crm64;

final class Crm64Card
{
    public function __construct(
        private readonly Crm64Access $access,
        private readonly Crm64Orders $orders,
        private readonly Crm64Operations $operations,
        private readonly Crm64Finance $finance,
        private readonly Crm64Notes $notes,
        private readonly Crm64Audit $audit,
        private readonly Crm64Timeline $timeline,
    ) {}

    public function show(string $orderKey): array
    {
        $actor = $this->access->actor(Crm64Access::VIEW);
        $order = $this->orders->order($orderKey);
        $result = [
            'order_id' => (int) $order->id,
            'order_key' => (string) $order->order_key,
            'payment_status' => (string) $order->payment_status,
            'returns' => $this->operations->returns((int) $order->id),
            'delivery' => $this->operations->shipping((int) $order->id),
            'finance' => $this->access->has(Crm64Access::FINANCE)
                ? $this->finance->forOrder((int) $order->id)
                : ['restricted' => true],
            'fiscal' => $this->operations->fiscal((int) $order->id),
            'notes' => $this->notes->history((int) $order->id),
            'timeline' => $this->timeline->collect((int) $order->id, 50),
            'read_only_projection' => true,
            'no_provider_post_executed' => true,
        ];
        $this->audit->viewed((int) $order->id, $actor, 'card');
        return $result;
    }

    public function timeline(string $orderKey, int $limit = 50): array
    {
        $actor = $this->access->actor(Crm64Access::VIEW);
        $order = $this->orders->order($orderKey);
        $result = $this->timeline->collect((int) $order->id, $limit);
        $this->audit->viewed((int) $order->id, $actor, 'timeline');
        return $result;
    }

    public function finance(string $orderKey): array
    {
        $actor = $this->access->actor(Crm64Access::FINANCE);
        $order = $this->orders->order($orderKey);
        $result = $this->finance->forOrder((int) $order->id);
        $this->audit->viewed((int) $order->id, $actor, 'finance');
        return $result;
    }

    public function accessHistory(string $orderKey, int $limit = 30): array
    {
        $this->access->actor(Crm64Access::REPORT);
        $order = $this->orders->order($orderKey);
        return $this->audit->accessHistory((int) $order->id, $limit);
    }
}
