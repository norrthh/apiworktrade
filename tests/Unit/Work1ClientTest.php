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

    private function client(FakeWork1Transport $transport): Work1Client
    {
        return new Work1Client($transport, new FormParser);
    }
}
