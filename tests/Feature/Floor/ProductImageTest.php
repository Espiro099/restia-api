<?php

namespace Tests\Feature\Floor;

use App\Models\Branch;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Foto del producto (M-12): carga, reemplazo, borrado y entrega pública. */
#[RequiresPhpExtension('gd')]
class ProductImageTest extends FloorTestCase
{
    private function v(int $version): array
    {
        return ['If-Match' => '"'.$version.'"'];
    }

    /** Imagen generada con GD, guardada en un archivo temporal. */
    private function picture(int $width, int $height, string $type = 'png'): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 80, 30));
        $path = tempnam(sys_get_temp_dir(), 'img');
        $type === 'png' ? imagepng($img, $path) : imagejpeg($img, $path);

        return $path;
    }

    private function upload(string $who, string $productId, ?string $path, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $files = $path ? ['image' => new UploadedFile($path, 'foto.png', null, null, true)] : [];
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->staff[$who]['access'], 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('PUT', '/api/v1/branches/'.$this->branch->uuid."/products/$productId/image", [], [], $files, $server);
    }

    private function menuVersion(): int
    {
        return (int) Branch::query()->find($this->branch->getKey())->menu_version;
    }

    private function imagePath(string $url): string
    {
        $this->assertMatchesRegularExpression('#/api/v1/public/images/[0-9a-f-]{36}$#', $url);

        return substr($url, strpos($url, '/api/v1/'));
    }

    public function test_sube_la_foto_la_reduce_y_la_sirve_sin_sesion(): void
    {
        $id = $this->product('TACOS');
        $menu = $this->menuVersion();

        $p = $this->upload('gerente', $id, $this->picture(2400, 1200), $this->v(1))->assertOk()
            ->assertHeader('ETag', '"2"')->json('data');
        $this->assertSame($menu + 1, $this->menuVersion());
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/api/v1/public/images/', $p['imageUrl']);

        $image = $this->get($this->imagePath($p['imageUrl']))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');
        $bytes = $image->getContent();
        $size = getimagesizefromstring($bytes);
        $this->assertSame([1200, 600, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);
        $this->get($this->imagePath($p['imageUrl']), ['If-None-Match' => $image->headers->get('ETag')])->assertStatus(304);

        // La app y el menú QR ven la misma URL.
        $menuItem = collect($this->as('mesero', 'GET', 'menu')->json('data.products'))->firstWhere('id', $id);
        $this->assertSame($p['imageUrl'], $menuItem['imageUrl']);

        $audit = json_decode(json_encode(DB::connection('mongodb')->table('audit_log')->where('action', 'product.image_changed')->first()), true);
        $this->assertSame(1200, $audit['changes']['after']['image_width']);
        $this->assertArrayNotHasKey('data', $audit['changes']['after']);
    }

    public function test_cambiar_la_foto_borra_la_anterior_y_quitarla_con_null(): void
    {
        $id = $this->product('TACOS');
        $first = $this->upload('gerente', $id, $this->picture(400, 400, 'jpeg'), $this->v(1))->assertOk()->json('data.imageUrl');
        $second = $this->upload('gerente', $id, $this->picture(500, 300), $this->v(2))->assertOk()->json('data.imageUrl');
        $this->assertNotSame($first, $second);
        $this->get($this->imagePath($first))->assertNotFound();
        $this->get($this->imagePath($second))->assertOk();
        $this->assertSame(1, ProductImage::query()->count());

        $path = "products/$id";
        $this->as('gerente', 'PATCH', $path, ['imageUrl' => 'https://otro.mx/foto.jpg'], $this->v(3))->assertStatus(422);
        $this->as('gerente', 'PATCH', $path, ['imageUrl' => null], $this->v(3))->assertOk()
            ->assertJsonPath('data.imageUrl', null)->assertHeader('ETag', '"4"');
        $this->get($this->imagePath($second))->assertNotFound();
        $this->assertSame(0, ProductImage::query()->count());
        // Sin foto, quitarla otra vez no cambia nada.
        $this->as('gerente', 'PATCH', $path, ['imageUrl' => null], $this->v(4))->assertOk()->assertHeader('ETag', '"4"');
    }

    public function test_rechazos(): void
    {
        $id = $this->product('TACOS');
        $this->upload('gerente', $id, $this->picture(400, 400))->assertStatus(400)->assertJsonPath('error.code', 'VERSION_REQUIRED');
        $this->upload('mesero', $id, $this->picture(400, 400), $this->v(1))->assertStatus(403);
        $this->upload('gerente', $id, $this->picture(400, 400), $this->v(7))->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->upload('gerente', $id, $this->picture(60, 60), $this->v(1))->assertStatus(422);
        $this->upload('gerente', $id, null, $this->v(1))->assertStatus(422);

        $svg = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->upload('gerente', $id, $svg, $this->v(1))->assertStatus(422)
            ->assertJsonPath('error.details.fields.image.0', 'La foto debe ser JPG, PNG o WebP.');

        $this->assertSame(0, ProductImage::query()->count());
        $this->get('/api/v1/public/images/'.Str::uuid())->assertNotFound();
        $this->get('/api/v1/public/images/no-es-uuid')->assertNotFound();
        // Al crear un producto no se acepta una URL de foto.
        $this->as('gerente', 'POST', 'products', [
            'code' => 'NUEVO', 'name' => 'Nuevo', 'category' => 'Otros', 'price' => ['amount' => '10', 'currency' => 'MXN'],
            'fiscal' => ['satProductCode' => '90101501', 'satUnitCode' => 'E48', 'taxObject' => '02', 'taxRate' => '0.16'],
            'imageUrl' => 'https://otro.mx/foto.jpg',
        ])->assertStatus(422);
    }
}
