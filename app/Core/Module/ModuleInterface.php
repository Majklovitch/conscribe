<?php
namespace App\Core\Module;

use App\Core\Http\Request;
use App\Core\Http\Router;

/**
 * Kontrakt modulu. Modul je samostatná složka v app/Modules/ se souborem Module.php,
 * kterou lze přidat i smazat, aniž by se muselo sahat do jádra nebo do index.php.
 */
interface ModuleInterface {
    /**
     * Název modulu (odpovídá názvu složky).
     */
    public function name(): string;

    /**
     * Spustí se před routováním. Vrací request – modul ho může upravit,
     * například odebrat z cesty vlastní prefix (/en/...).
     */
    public function boot(Request $request): Request;

    /**
     * Registrace vlastních tras modulu.
     */
    public function registerRoutes(Router $router): void;

    /**
     * Absolutní cesta k souboru s globálními funkcemi modulu, nebo null.
     */
    public function helpers(): ?string;
}
