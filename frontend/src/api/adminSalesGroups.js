import client from './client'

/**
 * Cégek közötti (superadmin) értékesítő csoport végpontok — olvasás + CRUD.
 *
 * Szándékosan külön modul a `salesGroups.js`-től, ugyanazért, amiért a backenden
 * is külön kontroller (`AdminSalesGroupController`) áll: a `salesGroups.js`
 * minden hívása az AKTUÁLIS cégre scope-olt végpontokra megy, ezek pedig nem. A
 * két hívás-halmaz külön tartása megakadályozza, hogy egy cross-company kérés
 * véletlenül a cégre scope-olt útvonalak közé keveredjen.
 *
 * Jogosultság: az olvasás kapuja a `sales_group.view_cross_company` kulcs
 * (superadmin-only), az íráson hard superadmin-kapu van (nincs hozzá
 * permission-kulcs). Lapozás nincs — a lista egyetlen válaszban jön.
 *
 * A `create` cél cégét a törzs `company_id` mezője adja; az `update`/`remove`
 * a cél céget a bound modellből oldja fel, ezért nekik nem kell company_id.
 */
export const adminSalesGroups = {
  list:   ()         => client.get('/api/admin/sales-groups'),
  create: (data)     => client.post('/api/admin/sales-groups', data),
  update: (id, data) => client.put(`/api/admin/sales-groups/${id}`, data),
  remove: (id)       => client.delete(`/api/admin/sales-groups/${id}`),
}
