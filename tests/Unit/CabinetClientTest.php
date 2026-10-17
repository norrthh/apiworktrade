<?php

namespace Tests\Unit;

use App\Services\Work1\CabinetClient;
use App\Services\Work1\FormParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWork1Transport;

class CabinetClientTest extends TestCase
{
    public function test_list_reads_cards_and_passes_the_cabinet_filters(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/cabinet/cards', <<<'HTML'
            <div class="deal_div" data-id="7926">
                <ul>
                    <li><span>Номер телефона</span><p>+79887034414</p></li>
                    <li><span>Наименование банка</span><p>Мегафон</p></li>
                    <li><span>Получатель</span><p>Andrei</p></li>
                </ul>
            </div>
        HTML);

        $result = $this->client($transport)->listCards([
            'archive_status' => 'active',
            'bank_id' => 98,
            'sort_by' => 'created_at',
        ]);

        $this->assertFalse($result['deleted']);
        $this->assertSame(7926, $result['requisites'][0]['id']);
        $this->assertSame('+79887034414', $result['requisites'][0]['number']);
        $this->assertStringContainsString('archive_status=active', $transport->gets[0]);
        $this->assertStringContainsString('bank_id=98', $transport->gets[0]);
        $this->assertStringContainsString('sort_by=created_at', $transport->gets[0]);
    }

    public function test_create_posts_the_trader_form_and_returns_only_the_new_card(): void
    {
        $transport = new FakeWork1Transport;
        $form = <<<'HTML'
            <form action="/cabinet/cards" method="post">
                <input type="hidden" name="_csrf-frontend" value="tok">
                <input name="RequisitesForm[number]" value="">
                <input name="RequisitesForm[recipient]" value="">
                <select name="RequisitesForm[method]"><option value="">Выберите метод</option><option value="sim">sim</option></select>
                <select name="RequisitesForm[bank_id]"><option value=""></option></select>
                <input name="RequisitesForm[device_id]" value="">
            </form>
        HTML;
        $transport->push('/cabinet/cards', $form.'<div class="deal_div" data-id="1"><ul><li><span>Номер телефона</span><p>+70000000000</p></li></ul></div>');
        $transport->push('/cabinet/cards', '', 302, '/cabinet/cards');
        $transport->push('/cabinet/cards', '<div class="deal_div" data-id="1"><ul><li><span>Номер телефона</span><p>+70000000000</p></li></ul></div><div class="deal_div" data-id="8000"><ul><li><span>Номер телефона</span><p>+79887034414</p></li><li><span>Получатель</span><p>Andrei</p></li><li><span>Наименование банка</span><p>Мегафон</p></li></ul></div>');

        $result = $this->client($transport)->createCard([
            'number' => '+79887034414',
            'recipient' => 'Andrei',
            'method' => 'sim',
            'bank_id' => '98',
        ]);

        $this->assertSame(8000, $result['id']);
        $this->assertTrue($result['created']);
        $this->assertSame('+79887034414', $transport->posts[0]['fields']['RequisitesForm[number]']);
        $this->assertSame('Andrei', $transport->posts[0]['fields']['RequisitesForm[recipient]']);
        $this->assertSame('sim', $transport->posts[0]['fields']['RequisitesForm[method]']);
        $this->assertSame('98', $transport->posts[0]['fields']['RequisitesForm[bank_id]']);
        $this->assertSame('/cabinet/cards', $transport->posts[0]['path']);
    }

    public function test_disable_sends_archive_zero_and_delete_sends_archive_one(): void
    {
        $transport = new FakeWork1Transport;
        $client = $this->client($transport);

        $client->disableCard(7926);
        $client->deleteCard(7926);
        $client->enableCard(7926);
        $client->setCardsActive([7926, 11320], false);

        $this->assertSame('0', $transport->posts[0]['fields']['delete_requisite[archive]']);
        $this->assertSame('7926', $transport->posts[0]['fields']['delete_requisite[id]']);
        $this->assertTrue($transport->posts[0]['ajax']);
        $this->assertSame('1', $transport->posts[1]['fields']['delete_requisite[archive]']);
        $this->assertSame('7926', $transport->posts[2]['fields']['return_requisite']);
        $this->assertSame('/cabinet/archive', $transport->posts[3]['path']);
        $this->assertSame(['7926', '11320'], $transport->posts[3]['fields']['requisites_id']);
        $this->assertSame('1', $transport->posts[3]['fields']['archive']);
    }

    public function test_get_card_unwraps_the_ajax_form_and_hides_the_pan(): void
    {
        $transport = new FakeWork1Transport;
        $html = <<<'HTML'
            <form action="/cabinet/update-requisite?id=10">
                <input type="hidden" name="_csrf-frontend" value="tok">
                <input name="RequisitesForm[number]" value="4578891234567890">
                <input name="RequisitesForm[recipient]" value="Aram">
                <select name="RequisitesForm[method]"><option value="c2c" selected>c2c</option></select>
                <select name="RequisitesForm[bank_id]"><option value="4" selected>Инекобанк</option></select>
                <select name="RequisitesForm[device_id]"><option value="175" selected>anton</option></select>
            </form>
        HTML;
        $transport->respond('/cabinet/update-requisite', json_encode($html));

        $result = $this->client($transport)->getCard(10);

        $this->assertSame('c2c', $result['method']);
        $this->assertSame('4', $result['bank_id']);
        $this->assertSame('Aram', $result['recipient']);
        $this->assertSame('7890', $result['fields']['RequisitesForm[number]']);
        $this->assertArrayNotHasKey('_csrf-frontend', $result['fields']);
        $this->assertTrue($transport->posts[0]['ajax']);
    }

    public function test_banks_reads_the_method_payload(): void
    {
        $transport = new FakeWork1Transport;
        $transport->respond('/cabinet/get-banks', json_encode([
            'banks' => [
                ['id' => 27, 'name' => 'АЛЬФА-БАНК'],
            ],
        ]));

        $result = $this->client($transport)->banks('m2alfa');

        $this->assertSame('m2alfa', $transport->posts[0]['fields']['method']);
        $this->assertSame([['id' => 27, 'name' => 'АЛЬФА-БАНК']], $result['banks']);
    }

    private function client(FakeWork1Transport $transport): CabinetClient
    {
        return new CabinetClient($transport, new FormParser);
    }
}
