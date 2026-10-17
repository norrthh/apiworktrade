<?php

namespace Tests\Unit;

use App\Services\Work1\FormParser;
use App\Services\Work1\Work1Client;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWork1Transport;

class Work1ClientTest extends TestCase
{
    public function test_create_returns_existing_offer_without_posting(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/offers/index', <<<'HTML'
            <tr data-key="15"><td>M_QHGRDP5U</td><td>Shop</td><td>humo</td><td></td></tr>
        HTML);

        $result = $this->client($transport)->createOffer('M_QHGRDP5U', 'Shop', 'humo');

        $this->assertFalse($result['created']);
        $this->assertSame(15, $result['offer_id']);
        $this->assertSame([], $transport->posts);
    }

    public function test_enable_trader_skips_toggles_that_are_already_on(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/offers/update', <<<'HTML'
            <tr><td>Тестов Тест</td><td><input name="user_id" value="1"><span class="fa fa-toggle-on"></span></td></tr>
        HTML);
        $transport->respond('/admin/site/update', <<<'HTML'
            <tr data-offer="15">
                <input name="payin_com" value="3.25">
                <input name="payout_com" value="1.25">
                <i class="fa fa-toggle-on"></i>
                <i class="fa fa-toggle-off"></i>
            </tr>
        HTML);

        $result = $this->client($transport)->enableTrader(15, 1);

        $this->assertSame(['/admin/offers/toggle-payment-type'], array_column($transport->posts, 'path'));
        $this->assertSame('payout', $transport->posts[0]['fields']['type']);
        $this->assertFalse($result['payout_enabled']);
    }

    public function test_unarchive_forces_archive_off_and_hides_the_card_number(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/requisite/update', <<<'HTML'
            <form>
                <input type="hidden" name="RequisiteUpdate[archive]" value="0">
                <input type="checkbox" name="RequisiteUpdate[archive]" value="1" checked>
                <input type="checkbox" name="RequisiteUpdate[status]" value="1" checked>
                <input name="RequisiteUpdate[number]" value="8600123412348412">
                <input type="password" name="RequisiteUpdate[password]" value="secret">
            </form>
        HTML);

        $result = $this->client($transport)->unarchiveRequisite(11697);

        $this->assertSame([
            'requisite_id' => 11697,
            'archive' => true,
            'status' => '1',
        ], $result);
        $this->assertSame('0', $transport->posts[0]['fields']['RequisiteUpdate[archive]']);
        $this->assertSame('1', $transport->posts[0]['fields']['RequisiteUpdate[status]']);
        $this->assertArrayNotHasKey('RequisiteUpdate[password]', $transport->posts[0]['fields']);
        $this->assertStringNotContainsString('8600123412348412', json_encode($result));
    }

    public function test_set_rate_writes_oracle_json_into_both_fields(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/offers/update', <<<'HTML'
            <form>
                <input name="OfferForm[merchant_code]" value="M_QHGRDP5U">
                <input name="OfferForm[oracle_source]" value="">
                <input name="OfferForm[oracle_source_payout]" value="">
            </form>
        HTML);

        $this->client($transport)->setRate(15, 'Htxuzs5mil');

        $expected = '{"market":"p2p","preset":"Htxuzs5mil","side":"buy"}';
        $this->assertSame($expected, $transport->posts[0]['fields']['OfferForm[oracle_source]']);
        $this->assertSame($expected, $transport->posts[0]['fields']['OfferForm[oracle_source_payout]']);
    }

    public function test_create_bank_posts_the_full_form_and_rereads_it(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/bank/update', <<<'HTML'
            <form>
                <input name="BankForm[name]" value="Ipak Yuli">
                <input name="BankForm[code]" value="ipak">
            </form>
        HTML);
        $transport->respond('/admin/bank/create', <<<'HTML'
            <form>
                <input name="BankForm[name]" value="">
                <input name="BankForm[code]" value="">
                <input name="patterns_new[' + newPatternIndex + '][type]" value="amount">
            </form>
        HTML, 302, '/admin/bank/update?id=9');

        $result = $this->client($transport)->createBank(['name' => 'Ipak Yuli', 'code' => 'ipak']);

        $this->assertSame(9, $result['bank_id']);
        $this->assertSame('Ipak Yuli', $result['fields']['BankForm[name]']);
        $this->assertSame('Ipak Yuli', $transport->posts[0]['fields']['BankForm[name]']);
        $this->assertSame('/admin/bank/create', $transport->posts[0]['path']);
        $this->assertArrayNotHasKey("patterns_new[' + newPatternIndex + '][type]", $transport->posts[0]['fields']);
    }

    public function test_update_bank_changes_only_the_given_field(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/bank/update', <<<'HTML'
            <form>
                <input name="BankForm[name]" value="Ipak Yuli">
                <input name="BankForm[code]" value="ipak">
            </form>
        HTML);

        $this->client($transport)->updateBank(9, ['name' => 'New name']);

        $this->assertSame('New name', $transport->posts[0]['fields']['BankForm[name]']);
        $this->assertSame('ipak', $transport->posts[0]['fields']['BankForm[code]']);
        $this->assertSame('/admin/bank/update?id=9', $transport->posts[0]['path']);
    }

    public function test_create_requisite_follows_the_index_link_and_hides_the_pan(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/requisite/update', <<<'HTML'
            <form>
                <input name="RequisiteUpdate[number]" value="8600123412348412">
                <input name="RequisiteUpdate[bank_id]" value="4">
                <input type="checkbox" name="RequisiteUpdate[status]" value="1" checked>
            </form>
        HTML);
        $transport->respond('/admin/requisite/open-form', <<<'HTML'
            <form>
                <input name="RequisiteUpdate[number]" value="">
                <input name="RequisiteUpdate[bank_id]" value="">
            </form>
        HTML, 200, '/admin/requisite/update?id=11699');
        $transport->respond('/admin/requisite/index', <<<'HTML'
            <a href="/admin/requisite/update?id=1">edit</a>
            <a href="/admin/requisite/open-form">new card</a>
        HTML);

        $result = $this->client($transport)->createRequisite([
            'number' => '8600123412348412',
            'bank_id' => '4',
        ]);

        $this->assertSame(11699, $result['requisite_id']);
        $this->assertSame('8412', $result['fields']['RequisiteUpdate[number]']);
        $this->assertSame('8600123412348412', $transport->posts[0]['fields']['RequisiteUpdate[number]']);
        $this->assertSame('/admin/requisite/open-form', $transport->posts[0]['path']);
        $this->assertStringNotContainsString('8600123412348412', json_encode($result));
    }

    public function test_list_requisites_filters_by_numeric_user_and_masks_the_grid(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/requisite/index', <<<'HTML'
            <table>
                <thead><tr><th>Number</th></tr></thead>
                <tr data-key="11697"><td>8600123412348412</td></tr>
            </table>
        HTML);

        $result = $this->client($transport)->listRequisites(28, 1);

        $this->assertSame(28, $result['user_id']);
        $this->assertSame('8412', $result['requisites'][0]['cells']['Number']);
        $this->assertStringContainsString('RequisiteSearch', $transport->gets[0]);
        $this->assertStringContainsString('28', $transport->gets[0]);
    }

    public function test_create_method_posts_the_type_and_banks_and_skips_the_empty_default_bank(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/methods/index', <<<'HTML'
            <table><thead><tr><th>Methods</th></tr></thead></table>
        HTML);
        $transport->respond('/admin/methods/create', <<<'HTML'
            <form>
                <input name="Methods[name]" value="">
                <select name="Methods[method_type_id]">
                    <option value="">Select</option>
                    <option value="2">tel</option>
                </select>
                <select name="Methods[bank_ids][]" multiple>
                    <option value="115">empty</option>
                    <option value="51">ОЗОН Банк</option>
                </select>
                <input name="Methods[auto_commission]" value="0.00">
            </form>
        HTML, 302, '/admin/methods/update?id=120');
        $transport->respond('/admin/methods/update', <<<'HTML'
            <form>
                <input name="Methods[name]" value="m2nozon">
                <select name="Methods[method_type_id]">
                    <option value="2" selected>tel</option>
                </select>
                <select name="Methods[bank_ids][]" multiple>
                    <option value="51" selected>ОЗОН Банк</option>
                </select>
                <input name="Methods[auto_commission]" value="0.00">
            </form>
        HTML);

        $result = $this->client($transport)->createMethod([
            'name' => 'm2nozon',
            'method_type' => 'tel',
            'bank_ids' => '51',
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame(120, $result['method_id']);
        $this->assertSame('tel', $result['method_type']);
        $this->assertSame([['id' => '51', 'name' => 'ОЗОН Банк']], $result['banks']);
        $this->assertSame(['51'], $transport->posts[0]['fields']['Methods[bank_ids][]']);
        $this->assertArrayNotHasKey('Methods[bank_ids]', $transport->posts[0]['fields']);
        $this->assertSame('2', $transport->posts[0]['fields']['Methods[method_type_id]']);
        $this->assertSame('m2nozon', $transport->posts[0]['fields']['Methods[name]']);
        $this->assertSame('/admin/methods/create', $transport->posts[0]['path']);
    }

    public function test_create_method_returns_an_existing_row_without_posting(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/admin/methods/index', <<<'HTML'
            <table><thead><tr><th>Methods</th></tr></thead>
            <tr data-key="19"><td>m2alfa</td></tr>
            </table>
        HTML);
        $transport->respond('/admin/methods/update', <<<'HTML'
            <form>
                <input name="Methods[name]" value="m2alfa">
                <select name="Methods[method_type_id]"><option value="2" selected>tel</option></select>
                <select name="Methods[bank_ids][]"><option value="27" selected>АЛЬФА-БАНК</option></select>
            </form>
        HTML);

        $result = $this->client($transport)->createMethod([
            'name' => 'm2alfa',
            'method_type' => 'tel',
            'bank_ids' => '27',
        ]);

        $this->assertFalse($result['created']);
        $this->assertSame(19, $result['method_id']);
        $this->assertSame([], $transport->posts);
    }

    private function client(FakeWork1Transport $transport): Work1Client
    {
        return new Work1Client($transport, new FormParser);
    }
}
