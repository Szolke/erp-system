// Értékesítő csoport segédfüggvények — a cégen belüli (SalesGroupPage) és a
// cégek közötti (AdminSalesGroupPage) út, valamint a közös SalesGroupForm
// osztozik rajtuk. Külön modulban, hogy a komponens-fájlok csak komponenst
// exportáljanak (react-refresh).

/**
 * Csoport megjelenítőneve — ugyanaz a képlet, mint a backend
 * SalesGroupResource-ában: prefix nélküli cégnél a nyers név.
 */
export function salesGroupDisplayName(prefix, name) {
  return prefix ? `${prefix}_${name}` : name
}

/**
 * Egy mező szerver-oldali hibája olvasható szövegként. A Laravel `errors`
 * objektuma mezőnként tömböt ad; a defenzív ág arra van, ha egyszer sima
 * stringet kapnánk.
 *
 * Nem értékesítő-csoport-specifikus: ha egy másik űrlap is használni kezdi,
 * emeld ki egy általános form-hiba modulba.
 */
export function fieldError(fieldErrors, key) {
  const value = fieldErrors?.[key]
  if (!value) return ''
  return Array.isArray(value) ? value.join(' ') : String(value)
}

/**
 * Cégek közötti írási hibák felhasználóbarát üzenete (Laravel-alak:
 * { message, errors }).
 *
 * A 403 itt SZÁNDÉKOS backend-viselkedés, nem programhiba: a FormRequest
 * `authorize()`-a a MÁR átállított cél-cég kontextuson fut, ezért kikapcsolt
 * `sales_group` modulú cégbe superadmin sem ír. A Laravel ilyenkor az angol
 * "This action is unauthorized." üzenetet adja — helyette magyarázó magyar
 * szöveget mutatunk, mert a cégválasztó a modul-állapotot nem tudja előre
 * jelezni (a `GET /api/companies` payload nem közli).
 */
export function describeCrossCompanyError(error) {
  const status  = error.response?.status
  const message = error.response?.data?.message

  if (status === 403) {
    return 'A művelet nem engedélyezett a cél cégben — az Értékesítő csoportok modul '
      + 'valószínűleg ki van kapcsolva nála. Kapcsold be a cég moduljainál, majd próbáld újra.'
  }
  if (status === 404) {
    return 'A csoport már nem létezik — időközben törölhették. Töltsd újra a listát.'
  }
  // 422: a prefix-hiány mezőhöz nem köthető `message`-t ad (nincs `errors`),
  // azt szó szerint megjelenítjük — a mezőnkénti hibákat a form kezeli.
  if (status === 422 && message) return message
  if (!status || status >= 500) return 'A művelet nem sikerült, próbáld újra.'

  return message ?? 'A művelet nem sikerült.'
}
