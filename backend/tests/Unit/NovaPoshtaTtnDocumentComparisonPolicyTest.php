<?php

namespace Tests\Unit;

use App\Services\Pricing\NovaPoshtaTtnDocumentComparisonPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NovaPoshtaTtnDocumentComparisonPolicyTest extends TestCase
{
    private const REF = '11111111-1111-4111-8111-111111111111';
    private const SENDER = '22222222-2222-4222-8222-222222222222';
    private const RECIPIENT = '33333333-3333-4333-8333-333333333333';
    private const CITY_A = '44444444-4444-4444-8444-444444444444';
    private const CITY_B = '55555555-5555-4555-8555-555555555555';
    private const WAREHOUSE = '66666666-6666-4666-8666-666666666666';

    private function expected(): array
    {
        return [
            'Ref' => self::REF, 'IntDocNumber' => '20450000000001',
            'Sender' => self::SENDER, 'Recipient' => self::RECIPIENT,
            'CitySender' => self::CITY_A, 'CityRecipient' => self::CITY_B,
            'RecipientAddress' => self::WAREHOUSE,
            'ServiceType' => 'WarehouseWarehouse', 'Weight' => 700, 'Cost' => 2000,
        ];
    }

    private function document(): array
    {
        return array_merge($this->expected(), ['Weight' => '0.700', 'Cost' => '20.00']);
    }

    private function compare(array $changes = []): array
    {
        return (new NovaPoshtaTtnDocumentComparisonPolicy())->compare(
            array_replace($this->document(), $changes), $this->expected()
        );
    }

    public function test_all_ten_fields_match_using_integer_minor_units(): void
    {
        $result = $this->compare();
        $this->assertSame('snapshot_consistent', $result['summary']);
        $this->assertCount(10, $result['checks']);
        $this->assertSame(array_fill_keys(array_keys($result['checks']), 'match'), $result['checks']);
    }

    public function test_zero_padded_gram_decimal_matches(): void
    {
        $this->assertSame('match', $this->compare(['Weight' => '0.7'])['checks']['Weight']);
    }

    public function test_integer_money_is_accepted_as_minor_units_after_scaling(): void
    {
        $this->assertSame('match', $this->compare(['Cost' => '20'])['checks']['Cost']);
    }

    public function test_different_cost_is_a_mismatch(): void
    {
        $result = $this->compare(['Cost' => '21.00']);
        $this->assertSame('snapshot_mismatch', $result['summary']);
        $this->assertSame('mismatch', $result['checks']['Cost']);
    }

    public function test_wrong_recipient_is_a_mismatch(): void
    {
        $this->assertSame('mismatch', $this->compare([
            'Recipient' => self::SENDER,
        ])['checks']['Recipient']);
    }

    public function test_missing_field_is_inconclusive(): void
    {
        $doc = $this->document();
        unset($doc['RecipientAddress']);
        $result = (new NovaPoshtaTtnDocumentComparisonPolicy())->compare($doc, $this->expected());
        $this->assertSame('inconclusive_fields', $result['summary']);
        $this->assertSame('missing', $result['checks']['RecipientAddress']);
    }

    public function test_invalid_uuid_field_is_inconclusive(): void
    {
        $this->assertSame('invalid', $this->compare([
            'Sender' => 'not-uuid',
        ])['checks']['Sender']);
    }

    public function test_invalid_weight_precision_does_not_round(): void
    {
        $this->assertSame('invalid', $this->compare([
            'Weight' => '0.7001',
        ])['checks']['Weight']);
    }

    public function test_invalid_cost_precision_does_not_round(): void
    {
        $this->assertSame('invalid', $this->compare([
            'Cost' => '20.001',
        ])['checks']['Cost']);
    }

    public function test_float_money_is_rejected(): void
    {
        $this->assertSame('invalid', $this->compare([
            'Cost' => 20.0,
        ])['checks']['Cost']);
    }

    public function test_mismatch_takes_priority_over_missing(): void
    {
        $this->assertSame('snapshot_mismatch', $this->compare([
            'Sender' => self::RECIPIENT, 'Recipient' => null,
        ])['summary']);
    }

    public function test_uppercase_uuid_normalizes_without_changing_snapshot(): void
    {
        $this->assertSame('match', $this->compare([
            'Recipient' => strtoupper(self::RECIPIENT),
        ])['checks']['Recipient']);
    }

    public function test_inconclusive_list_contains_no_actual_carrier_data(): void
    {
        $result = (new NovaPoshtaTtnDocumentComparisonPolicy())
            ->inconclusive('inconclusive_list');
        $this->assertSame('inconclusive_list', $result['summary']);
        $this->assertCount(10, $result['checks']);
        $this->assertNotContains('match', $result['checks']);
    }

    public function test_invalid_inconclusive_category_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NovaPoshtaTtnDocumentComparisonPolicy())->inconclusive('carrier_verified');
    }

    public function test_personal_data_is_not_mirrored_in_checks(): void
    {
        $result = $this->compare(['RecipientName' => 'Петренко',
            'RecipientsPhone' => '+380671234567']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Петренко', $encoded);
        $this->assertStringNotContainsString('671234567', $encoded);
    }
}
