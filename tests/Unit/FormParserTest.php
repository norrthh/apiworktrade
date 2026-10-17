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
}
