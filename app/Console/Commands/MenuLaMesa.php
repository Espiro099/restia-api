<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductPreparationRoute;
use App\Models\Station;
use App\Support\Floor;
use App\Support\Mongo;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/**
 * Carta base de La Mesa: 25 platillos (a Cocina) y 25 bebidas (a Barra), con descripción, precio, claves
 * fiscales y modificadores. Se puede correr las veces que haga falta: solo agrega lo que falte por código y
 * nunca cambia un producto, estación o modificador que ya exista (el gerente pudo haberlo editado en el panel).
 * Las fotos se suben después desde el panel (Menú > Foto).
 */
class MenuLaMesa extends Command
{
    protected $signature = 'restia:menu-la-mesa
        {--branch= : Código de la sucursal; por omisión, la única que exista}';

    protected $description = 'Agrega a una sucursal la carta base de La Mesa (25 platillos y 25 bebidas) sin duplicar';

    /** Claves SAT de ejemplo (servicio de alimentos, unidad de servicio, IVA 16 %), las mismas de restia:demo. */
    private const FISCAL = ['sat_product_code' => '90101501', 'sat_unit_code' => 'E48', 'tax_object' => '02', 'tax_rate' => '0.160000'];

    /** Platillos: código, nombre, categoría, precio, descripción, modificadores. */
    public const DISHES = [
        ['LM-GUACAMOLE', 'Guacamole de la casa', 'Entradas', '135', 'Aguacate machacado al momento con totopos.', ['SALSA']],
        ['LM-QUESO-FUNDIDO', 'Queso fundido con chorizo', 'Entradas', '145', 'Queso Chihuahua gratinado con chorizo y tortillas de harina.', []],
        ['LM-SOPES', 'Sopes de tinga', 'Entradas', '110', 'Tres sopes con tinga de pollo, crema y queso fresco.', ['SALSA']],
        ['LM-TOSTADAS-ATUN', 'Tostadas de atún', 'Entradas', '175', 'Atún fresco, aguacate, cebolla morada y aderezo de chipotle.', []],
        ['LM-ESQUITES', 'Esquites', 'Entradas', '75', 'Elote desgranado con mayonesa, queso y chile piquín.', []],
        ['LM-SOPA-TORTILLA', 'Sopa de tortilla', 'Sopas', '95', 'Caldo de jitomate, tiras de tortilla, aguacate y chicharrón.', []],
        ['LM-CREMA-ELOTE', 'Crema de elote', 'Sopas', '90', 'Crema de elote con rajas de poblano.', []],
        ['LM-CALDO-TLALPENO', 'Caldo tlalpeño', 'Sopas', '115', 'Caldo de pollo con garbanzo, verduras y chipotle.', []],
        ['LM-POZOLE', 'Pozole rojo', 'Sopas', '150', 'Pozole de cerdo con lechuga, rábano, orégano y tostadas.', []],
        ['LM-TACOS-PASTOR', 'Tacos al pastor', 'Tacos', '120', 'Cuatro tacos de cerdo al pastor con piña, cebolla y cilantro.', ['SALSA']],
        ['LM-TACOS-ARRACHERA', 'Tacos de arrachera', 'Tacos', '165', 'Tres tacos de arrachera con guacamole y cebollitas.', ['SALSA']],
        ['LM-TACOS-PESCADO', 'Tacos de pescado', 'Tacos', '150', 'Tres tacos de pescado capeado con col y aderezo de chipotle.', ['SALSA']],
        ['LM-TACOS-CAMARON', 'Tacos gobernador', 'Tacos', '175', 'Tres tacos de camarón con queso gratinado y pimiento.', ['SALSA']],
        ['LM-TACOS-CARNITAS', 'Tacos de carnitas', 'Tacos', '125', 'Cuatro tacos de carnitas con salsa verde y cebolla.', ['SALSA']],
        ['LM-ARRACHERA', 'Arrachera a la parrilla', 'Platos fuertes', '295', 'Arrachera de 300 g con frijoles charros, guacamole y tortillas.', ['TERMINO']],
        ['LM-RIB-EYE', 'Rib eye', 'Platos fuertes', '420', 'Rib eye de 350 g con papa al horno y verduras asadas.', ['TERMINO']],
        ['LM-ENCHILADAS-SUIZAS', 'Enchiladas suizas', 'Platos fuertes', '165', 'Rellenas de pollo, salsa verde cremosa y queso gratinado.', []],
        ['LM-MOLE-POBLANO', 'Mole poblano', 'Platos fuertes', '210', 'Pieza de pollo bañada en mole poblano con arroz.', []],
        ['LM-CHILE-RELLENO', 'Chile relleno', 'Platos fuertes', '175', 'Chile poblano capeado relleno de queso en caldillo de jitomate.', []],
        ['LM-SALMON', 'Salmón a las finas hierbas', 'Platos fuertes', '310', 'Filete de salmón con puré de papa y espárragos.', []],
        ['LM-POLLO-ASADO', 'Pollo asado al carbón', 'Platos fuertes', '185', 'Medio pollo marinado con arroz, frijoles y ensalada.', []],
        ['LM-FLAN', 'Flan napolitano', 'Postres', '75', 'Flan casero con caramelo.', []],
        ['LM-PASTEL-CHOCOLATE', 'Pastel de chocolate', 'Postres', '95', 'Pastel húmedo de chocolate con helado de vainilla.', []],
        ['LM-CHURROS', 'Churros con chocolate', 'Postres', '85', 'Cinco churros con azúcar y canela y chocolate caliente para mojar.', []],
        ['LM-PAY-QUESO', 'Pay de queso', 'Postres', '90', 'Pay de queso con mermelada de frutos rojos.', []],
    ];

    /** Bebidas: código, nombre, categoría, precio, descripción, modificadores. */
    public const DRINKS = [
        ['LM-AGUA-JAMAICA', 'Agua de jamaica', 'Aguas y refrescos', '45', 'Agua fresca natural, vaso de 500 ml.', ['HIELO']],
        ['LM-AGUA-HORCHATA', 'Agua de horchata', 'Aguas y refrescos', '45', 'Horchata de arroz con canela, vaso de 500 ml.', ['HIELO']],
        ['LM-LIMONADA', 'Limonada natural', 'Aguas y refrescos', '50', 'Limonada preparada al momento.', ['HIELO']],
        ['LM-NARANJADA', 'Naranjada mineral', 'Aguas y refrescos', '55', 'Jugo de naranja con agua mineral.', ['HIELO']],
        ['LM-REFRESCO', 'Refresco de lata', 'Aguas y refrescos', '40', 'Lata de 355 ml.', []],
        ['LM-AGUA-NATURAL', 'Agua natural embotellada', 'Aguas y refrescos', '35', 'Botella de 600 ml.', []],
        ['LM-AGUA-MINERAL', 'Agua mineral', 'Aguas y refrescos', '40', 'Botella de 355 ml.', []],
        ['LM-CAFE-AMERICANO', 'Café americano', 'Café y té', '40', 'Café de grano de Veracruz, taza de 240 ml.', ['LECHE']],
        ['LM-CAFE-OLLA', 'Café de olla', 'Café y té', '45', 'Café con piloncillo y canela.', []],
        ['LM-CAPUCHINO', 'Capuchino', 'Café y té', '55', 'Espresso doble con leche espumada.', ['LECHE']],
        ['LM-LATTE', 'Latte', 'Café y té', '55', 'Espresso con leche vaporizada.', ['LECHE']],
        ['LM-ESPRESSO', 'Espresso', 'Café y té', '40', 'Carga sencilla.', []],
        ['LM-CHOCOLATE', 'Chocolate caliente', 'Café y té', '55', 'Chocolate de mesa batido con leche.', ['LECHE']],
        ['LM-TE', 'Té de la casa', 'Café y té', '40', 'Manzanilla, menta o verde.', []],
        ['LM-CERVEZA-CLARA', 'Cerveza clara', 'Cervezas', '55', 'Botella de 355 ml.', []],
        ['LM-CERVEZA-OSCURA', 'Cerveza oscura', 'Cervezas', '60', 'Botella de 355 ml.', []],
        ['LM-CERVEZA-ARTESANAL', 'Cerveza artesanal', 'Cervezas', '95', 'Pregunta por la de temporada.', []],
        ['LM-MICHELADA', 'Michelada', 'Cervezas', '85', 'Cerveza clara con limón, sal y salsas.', []],
        ['LM-MARGARITA', 'Margarita', 'Cocteles', '120', 'Tequila, licor de naranja y limón.', ['HIELO']],
        ['LM-PALOMA', 'Paloma', 'Cocteles', '110', 'Tequila con refresco de toronja y limón.', ['HIELO']],
        ['LM-MOJITO', 'Mojito', 'Cocteles', '115', 'Ron blanco, hierbabuena, limón y agua mineral.', ['HIELO']],
        ['LM-CANTARITO', 'Cantarito', 'Cocteles', '135', 'Tequila con jugos de cítricos en jarrito de barro.', []],
        ['LM-CARAJILLO', 'Carajillo', 'Cocteles', '140', 'Licor 43 con espresso.', ['HIELO']],
        ['LM-VINO-TINTO', 'Copa de vino tinto', 'Vinos', '130', 'Vino tinto mexicano de la casa.', []],
        ['LM-VINO-BLANCO', 'Copa de vino blanco', 'Vinos', '130', 'Vino blanco mexicano de la casa.', []],
    ];

    /** Modificadores: código => nombre, mínimo, máximo, opciones (nombre, precio extra). */
    private const MODIFIERS = [
        'TERMINO' => ['Término de la carne', 1, 1, [['Rojo', '0'], ['Medio', '0'], ['Tres cuartos', '0'], ['Bien cocido', '0']]],
        'SALSA' => ['Salsa', 0, 2, [['Verde', '0'], ['Roja', '0'], ['Habanero', '0']]],
        'LECHE' => ['Leche', 0, 1, [['Entera', '0'], ['Deslactosada', '0'], ['De almendra', '12']]],
        'HIELO' => ['Hielo', 0, 1, [['Sin hielo', '0'], ['Poco hielo', '0']]],
    ];

    public function handle(): int
    {
        $branch = $this->option('branch')
            ? Branch::query()->where('code', $this->option('branch'))->first()
            : (Branch::query()->count() === 1 ? Branch::query()->first() : null);
        if (! $branch) {
            $this->error('Indica la sucursal con --branch.');

            return self::FAILURE;
        }
        $branchId = new ObjectId($branch->getKey());

        $created = Mongo::transaction(function () use ($branchId) {
            $kitchen = $this->station($branchId, 'COCINA', 'Cocina');
            $bar = $this->station($branchId, 'BARRA', 'Barra');
            $modifiers = [];
            foreach (self::MODIFIERS as $code => [$name, $min, $max, $options]) {
                $modifiers[$code] = $this->modifier($branchId, $code, $name, $min, $max, $options);
            }
            $taken = Product::query()->where('branch_id', $branchId)->pluck('code')->all();
            $count = 0;
            foreach ([[self::DISHES, $kitchen], [self::DRINKS, $bar]] as [$items, $station]) {
                foreach ($items as $i => [$code, $name, $category, $price, $description, $mods]) {
                    if (in_array($code, $taken, true)) {
                        continue;
                    }
                    $product = Product::query()->create([
                        'branch_id' => $branchId, 'code' => $code, 'name' => $name, 'description' => $description, 'category' => $category,
                        'display_order' => $i + 1, 'price' => bcadd($price, '0', 2), 'status' => Product::ACTIVE, 'availability' => 'AVAILABLE',
                        'modifier_ids' => array_map(fn ($m) => new ObjectId($modifiers[$m]->getKey()), $mods),
                        'current_recipe_version' => null, 'image_uuid' => null, 'fiscal' => self::FISCAL,
                    ]);
                    ProductPreparationRoute::query()->create([
                        'branch_id' => $branchId, 'product_id' => new ObjectId($product->getKey()), 'station_id' => new ObjectId($station->getKey()), 'priority' => 1,
                    ]);
                    $count++;
                }
            }
            // Los dispositivos vuelven a pedir el menú solo si cambió su versión.
            if ($count > 0) {
                Branch::query()->where('_id', $branchId)->increment('menu_version');
            }

            return $count;
        });

        $this->info($created > 0
            ? "Listo: se agregaron $created productos de la carta de La Mesa."
            : 'La sucursal ya tenía toda la carta de La Mesa; no se agregó nada.');

        return self::SUCCESS;
    }

    /** La estación con ese código; si no existe, se crea activa. Una existente se respeta tal como está. */
    private function station(ObjectId $branchId, string $code, string $name): Station
    {
        return Station::query()->where('branch_id', $branchId)->where('code', $code)->first()
            ?? Station::query()->create(['branch_id' => $branchId, 'code' => $code, 'name' => $name, 'status' => Station::ACTIVE]);
    }

    /** @param list<array{0: string, 1: string}> $options */
    private function modifier(ObjectId $branchId, string $code, string $name, int $min, int $max, array $options): Modifier
    {
        return Modifier::query()->where('branch_id', $branchId)->where('code', $code)->first()
            ?? Modifier::query()->create([
                'branch_id' => $branchId, 'code' => $code, 'name' => $name, 'min_selections' => $min, 'max_selections' => $max, 'status' => Modifier::ACTIVE,
                'options' => array_map(fn ($o) => ['uuid' => (string) Str::uuid(), 'name' => $o[0], 'price_delta' => Floor::decimal($o[1]), 'status' => 'ACTIVE'], $options),
            ]);
    }
}
