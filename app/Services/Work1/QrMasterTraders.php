<?php

namespace App\Services\Work1;

use Illuminate\Support\Facades\Cache;

final class QrMasterTraders
{
    public function __construct(private readonly Work1Transport $transport, private readonly FormParser $parser) {}

    /** @return array{id: int, name: string, email: string, active: bool} */
    public function find(int $id): array
    {
        $result = Cache::lock('qrmaster:trader-session', 180)->get(function () use ($id): array {
            $path = '/admin/site/update?id='.$id;
            $page = $this->transport->get($path);
            abort_if($page->status === 404, 404, 'Trader not found');
            $form = $this->parser->formContaining($page->body, 'UserUpdateForm[fio]');
            abort_unless($page->status === 200 && ($form['path'] ?? null) === $path, 503, 'Trader directory unavailable');
            $fields = $form['fields'];
            $status = $fields['UserUpdateForm[status]'] ?? '';
            abort_unless(in_array($status, ['10', '5', '1'], true), 503, 'Trader status unavailable');

            return ['id' => $id, 'name' => trim($fields['UserUpdateForm[fio]'] ?? ''),
                'email' => trim($fields['UserUpdateForm[email]'] ?? ''), 'active' => $status === '10'];
        });
        abort_if($result === false, 503, 'Trader directory busy');

        return $result;
    }
}
