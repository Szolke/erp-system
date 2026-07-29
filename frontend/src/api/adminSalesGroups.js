import client from './client'

/**
 * Cégek közötti (superadmin) értékesítő csoport nézet — CSAK OLVASÁS.
 *
 * Szándékosan külön modul a `salesGroups.js`-től, ugyanazért, amiért a backenden
 * is külön kontroller (`AdminSalesGroupController`) áll: a `salesGroups.js`
 * minden hívása az AKTUÁLIS cégre scope-olt végpontokra megy, ez az egy pedig
 * nem. A két hívás-halmaz külön tartása megakadályozza, hogy egy cross-company
 * lekérés véletlenül a cégre scope-olt útvonalak közé keveredjen.
 *
 * A végpont jogosultsági kapuja a `sales_group.view_cross_company` kulcs
 * (superadmin-only), és nincs lapozás — egyetlen listát ad vissza.
 */
export const adminSalesGroups = {
  list: () => client.get('/api/admin/sales-groups'),
}
