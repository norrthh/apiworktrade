<?php

namespace Tests\Unit;

use App\Services\Work1\FormParser;
use App\Services\Work1\Work1Exception;
use PHPUnit\Framework\TestCase;

class FormParserTest extends TestCase
{
    public function test_fields_keep_checked_boxes_and_drop_passwords(): void
    {
        $html = <<<'HTML'
            <form>
                <input type="hidden" name="_csrf-backend" value="tok">
                <input type="hidden" name="RequisiteUpdate[archive]" value="0">
                <input type="checkbox" name="RequisiteUpdate[archive]" value="1" checked>
                <input type="password" name="RequisiteUpdate[password]" value="secret">
                <input name="RequisiteUpdate[number]" value="8600123412348412">
                <select name="OfferForm[method]"><option value="humo" selected>humo</option></select>
            </form>
        HTML;

        $fields = (new FormParser)->fields($html);

        $this->assertSame('tok', $fields['_csrf-backend']);
        $this->assertSame('1', $fields['RequisiteUpdate[archive]']);
        $this->assertSame('humo', $fields['OfferForm[method]']);
        $this->assertArrayNotHasKey('RequisiteUpdate[password]', $fields);
    }

    public function test_index_row_reads_code_method_and_agent(): void
    {
        $html = <<<'HTML'
            <table><tr data-key="15">
                <td>M_QHGRDP5U</td><td>Shop</td><td>humo</td><td>superadmin</td>
            </tr></table>
        HTML;

        $rows = (new FormParser)->indexRows($html);

        $this->assertSame([
            'id' => 15,
            'merchant_code' => 'M_QHGRDP5U',
            'method' => 'humo',
            'agent' => 'superadmin',
        ], $rows[0]);
    }

    public function test_index_row_reads_a_method_other_than_humo(): void
    {
        $html = <<<'HTML'
            <table><tr data-key="411">
                <td>M_3RRVXQT1</td><td>EHOT</td><td>m2alfa</td><td>superadmin</td>
            </tr></table>
        HTML;

        $rows = (new FormParser)->indexRows($html);

        $this->assertSame('m2alfa', $rows[0]['method']);
        $this->assertSame('superadmin', $rows[0]['agent']);
    }

    public function test_trader_row_and_user_toggle(): void
    {
        $html = <<<'HTML'
            <tr data-offer="15">
                <input name="payin_com" value="3.25">
                <input name="payout_com" value="1.25">
                <i class="fa fa-toggle-on"></i>
                <i class="fa fa-toggle-off"></i>
            </tr>
            <tr>
                <td>Тестов Тест</td>
                <td><input name="user_id" value="1"><span class="fa fa-toggle-off"></span></td>
            </tr>
        HTML;

        $parser = new FormParser;

        $this->assertSame([
            'payin_com' => '3.25',
            'payout_com' => '1.25',
            'payin_enabled' => true,
            'payout_enabled' => false,
        ], $parser->traderRow($html, 15));
        $this->assertFalse($parser->userEnabled($html, 1));
    }

    public function test_apply_values_matches_a_suffix_and_rejects_an_unknown_field(): void
    {
        $parser = new FormParser;
        $fields = [
            'BankForm[name]' => 'Old',
            'BankForm[code]' => 'old',
        ];

        $applied = $parser->applyValues($fields, ['name' => 'Ipak Yuli']);

        $this->assertSame('Ipak Yuli', $applied['BankForm[name]']);
        $this->assertSame('old', $applied['BankForm[code]']);

        $this->expectException(Work1Exception::class);
        $parser->applyValues($fields, ['currency' => 'UZS']);
    }

    public function test_grid_and_public_fields_keep_only_the_last_four_digits(): void
    {
        $html = <<<'HTML'
            <table>
                <thead><tr><th>Bank</th><th>Number</th></tr></thead>
                <tr data-key="4"><td>Ipak</td><td>8600 1234 1234 8412</td></tr>
            </table>
        HTML;

        $rows = (new FormParser)->gridRows($html);

        $this->assertSame('8412', $rows[0]['cells']['Number']);
        $this->assertSame('8412', (new FormParser)->publicFields([
            'RequisiteUpdate[number]' => '8600123412348412',
        ])['RequisiteUpdate[number]']);
    }

    public function test_requisite_create_path_skips_update_and_search(): void
    {
        $html = <<<'HTML'
            <a href="/admin/requisite/update?id=1">edit</a>
            <a href="/admin/requisite/index">list</a>
            <a href="/admin/requisite/open-form">new</a>
            <form action="/admin/requisite/index">
                <input name="RequisiteSearch[user]" value="1">
            </form>
        HTML;

        $this->assertSame('/admin/requisite/open-form', (new FormParser)->requisiteCreatePath($html));
    }

    public function test_cabinet_cards_keep_a_phone_and_mask_a_pan(): void
    {
        $html = <<<'HTML'
            <div class="deal_div" data-id="7926">
                <ul>
                    <li><span>Номер телефона</span><p>+79887034414</p></li>
                    <li><span>Наименование банка</span><p>Мегафон</p></li>
                    <li><span>Получатель</span><p>Andrei</p></li>
                    <li><span>Название устройства</span><p>anton</p></li>
                    <li><span>Оборот за день</span><p class="hover-toggle"><span class="default">0.00 ₽</span><span class="hover">0.00 ₮</span></p></li>
                </ul>
                <span class="automation-status automation-status--active"></span>
            </div>
            <div class="deal_div archive" data-id="10">
                <ul>
                    <li><span>Номер Карты</span><p>4578 8912 3456 7890</p></li>
                    <li><span>Наименование банка</span><p>Инекобанк</p></li>
                    <li><span>Получатель</span><p>Aram</p></li>
                </ul>
            </div>
            <div class="deal_div deal_div_add"><span>Добавить реквизит</span></div>
        HTML;

        $cards = (new FormParser)->cabinetCards($html);

        $this->assertCount(2, $cards);
        $this->assertTrue($cards[0]['active']);
        $this->assertTrue($cards[0]['automated']);
        $this->assertSame('+79887034414', $cards[0]['number']);
        $this->assertSame('Номер телефона', $cards[0]['number_label']);
        $this->assertSame('Мегафон', $cards[0]['bank']);
        $this->assertSame('0.00 ₽', $cards[0]['turnover_day']);
        $this->assertFalse($cards[1]['active']);
        $this->assertSame('7890', $cards[1]['number']);
    }

    public function test_html_fragment_unwraps_a_json_string(): void
    {
        $html = '<form><input name="RequisitesForm[recipient]" value="Andrei"></form>';

        $this->assertSame($html, (new FormParser)->htmlFragment(json_encode($html)));
    }

    public function test_csrf_reads_the_trader_token(): void
    {
        $html = '<input type="hidden" name="_csrf-frontend" value="front">';

        $this->assertSame('front', (new FormParser)->csrf($html));
    }
}
