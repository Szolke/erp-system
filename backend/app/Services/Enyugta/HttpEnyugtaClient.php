<?php

namespace App\Services\Enyugta;

use App\Enums\EnyugtaMode;
use App\Models\CompanyEnyugtaCredential;
use RuntimeException;

/**
 * Kliens-váz a NAV eNyugta REST API-hoz (test/live mód). EBBEN A FÁZISBAN NEM
 * végez tényleges HTTP-hívást — fetchVatCategories() explicit hibát dob, amíg
 * a 2-3. fázisban az XML-builder + tényleges kérés el nem készül. A
 * protokoll-szintű segédfüggvények (base URL feloldás, jelszó-hash) viszont
 * már most implementálva vannak, mert nem igényelnek NAV-kapcsolatot és a
 * későbbi fázisok közvetlenül építhetnek rájuk.
 */
class HttpEnyugtaClient implements EnyugtaClientInterface
{
    public function __construct(
        private readonly EnyugtaMode $mode = EnyugtaMode::Test,
        private readonly ?string $baseUrlOverride = null,
    ) {}

    /**
     * A mode + config('erp.enyugta.base_url_test'/'base_url_live') alapján
     * állapítja meg a bázis URL-t, a base_url_override-ot (pl. egy cég
     * egyedi credential sora) mindig előnyben részesítve. A spec nem közöl
     * teljes URL-t (l. docs/nav-enyugta-spec-jegyzetek.md 3. fejezet) — amíg
     * ezt konfigurálatlanul hagyjuk, EnyugtaConfigurationException-t dob.
     */
    public function resolveBaseUrl(): string
    {
        if ($this->mode === EnyugtaMode::Mock) {
            throw new RuntimeException('resolveBaseUrl() nem hívható mock módban — a MockEnyugtaClient nem tesz HTTP-hívást.');
        }

        $base = $this->baseUrlOverride ?? match ($this->mode) {
            EnyugtaMode::Live => config('erp.enyugta.base_url_live'),
            EnyugtaMode::Test => config('erp.enyugta.base_url_test'),
            EnyugtaMode::Mock => null,
        };

        if ($base === null || $base === '') {
            throw new EnyugtaConfigurationException(sprintf(
                'Nincs konfigurálva base URL a(z) "%s" eNyugta módhoz — állítsd be az ENYUGTA_BASE_URL_%s környezeti változót, vagy adj meg base_url_override-ot a hitelesítő adatoknál.',
                $this->mode->value,
                strtoupper($this->mode->value),
            ));
        }

        return rtrim($base, '/').config('erp.enyugta.context_root');
    }

    /**
     * A NAV eNyugta interfész SHA3-512 hash-t vár a jelszóra
     * (docs/nav-enyugta-spec-jegyzetek.md 2. fejezet — FIGYELEM: ez eltér az
     * Online Számla 3.0 SHA-512 algoritmusától). PHP natív hash() függvény,
     * nincs hozzá vendor-függőség.
     */
    public function hashPassword(string $plainPassword): string
    {
        return hash('sha3-512', $plainPassword);
    }

    /**
     * TODO(requestSignature): a spec nem dokumentálja a requestSignature
     * konkatenációs képletét (docs/nav-enyugta-spec-jegyzetek.md 2. fejezet) —
     * a mező opcionális az AuthTokenRequest-ben (auth/requestSignature,
     * kötelező: nem), ezért egyelőre NEM számítjuk ki, a header nem
     * tartalmazza. Tisztázandó a NAV felé (enyugta@nav.gov.hu / GitHub
     * Discussions), mielőtt a tényleges beküldő logika (2-3. fázis) elkészül.
     *
     * @return array{login: string, passwordHash: string, taxNumber: string}
     */
    public function buildAuthHeader(CompanyEnyugtaCredential $credential): array
    {
        return [
            'login' => $credential->login,
            'passwordHash' => $this->hashPassword($credential->password),
            'taxNumber' => $credential->tax_number,
            // 'requestSignature' => TODO — l. fenti docblock
        ];
    }

    public function fetchVatCategories(): array
    {
        $this->resolveBaseUrl(); // konfiguráció-ellenőrzés akkor is fusson, ha a hívás maga még nincs implementálva

        throw new RuntimeException(
            'HttpEnyugtaClient::fetchVatCategories() még nincs implementálva — a tényleges NAV HTTP-hívás a következő fázis feladata. '
            .'Használj "mock" módot (config erp.enyugta.default_mode) a katalógus szinkronizálásához NAV-kapcsolat nélkül.'
        );
    }
}
