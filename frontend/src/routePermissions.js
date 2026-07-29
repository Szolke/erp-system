// Egy igazságforrás: route (abszolút path) → jogosultsági követelmény.
// A Layout.jsx (sidebar) ebből olvassa ki, mely menüpontok látszanak; az App.jsx
// (RequirePermission-nel becsomagolt route-ok) ugyanebből a route-szintű
// védelmet. A két hely soha nem futhat szét, mert mindkettő ugyanezt az
// objektumot nézi — új menüpont/route felvételekor csak itt kell módosítani.
//
// Követelmény-formák:
//   { public: true }          — authentikáción túl nincs feltétel
//   { perm: 'x.y' }           — can('x.y')
//   { anyPerm: ['a', 'b'] }   — can('a') || can('b')
//   { superadminOnly: true }  — user.is_superadmin (NEM helyettesíti/egészíti ki a can()-t —
//                                l. hasRouteAccess, ugyanígy viselkedett a régi Layout-logika is)
//   { check: (ctx) => bool }  — egyedi szabály, ctx = { user, can, params }
//
// Csak azok a route-ok szerepelnek itt, amikhez a sidebar ma is jogosultságot
// köt (l. docs/progress.md — Nyitólap, Wiki, és a sidebarból egyáltalán nem
// elérhető "árva" bizonylat-route-ok /invoices, /receipts/* szándékosan
// KIMARADNAK, ezek App.jsx-ben nincsenek RequirePermission-nel becsomagolva).
export const ROUTE_ACCESS = {
  '/': { public: true },
  '/wiki': { public: true },

  '/documents': { anyPerm: ['invoice.view', 'receipt.view'] },
  '/reports': { perm: 'report.view' },

  '/partners': { perm: 'partner.view' },
  '/partners/new': { perm: 'partner.view' },
  '/partners/:id/edit': { perm: 'partner.view' },

  '/products': { perm: 'product.view' },
  '/products/new': { perm: 'product.view' },
  '/products/:id/edit': { perm: 'product.view' },

  '/assets': { perm: 'asset.view' },
  '/assets/new': { perm: 'asset.view' },
  '/assets/:id/edit': { perm: 'asset.view' },

  '/enyugta/reports': { perm: 'enyugta.view' },
  '/enyugta/reports/:id': { perm: 'enyugta.view' },

  '/company': { perm: 'company.view' },
  '/audit-logs': { perm: 'audit.view' },
  '/nav-submissions': { perm: 'nav.log.view' },

  '/users': { perm: 'user.view' },
  // Kivétel: a backend UserController::show() a SAJÁT profilt user.view jog
  // nélkül is engedi (a token-eszközkezelőhöz kell), és a sidebar footer
  // profil-linkje (Layout.jsx) minden usernek megjelenik, jogosultságtól
  // függetlenül. A route-guard ezt a meglévő szabályt tükrözi vissza —
  // különben egy user.view nélküli felhasználó a SAJÁT profiljához sem
  // férne hozzá.
  '/users/:id': {
    check: ({ user, can, params }) => can('user.view') || Number(params?.id) === user?.id,
  },

  '/groups': { perm: 'group.view' },
  '/groups/:id': { perm: 'group.view' },

  '/companies': { superadminOnly: true },

  '/settings/document-series': { perm: 'document_series.manage' },
  '/settings/enyugta': { perm: 'enyugta.view' },
  '/settings/modules': { superadminOnly: true },
  '/settings/custom-fields': { perm: 'company.manage' },
  '/settings/api-tester': { perm: 'api_tester.use' },
  '/settings/countries': { superadminOnly: true },
  '/settings/job-positions': { perm: 'job_position.manage' },
  '/settings/asset-types': { perm: 'asset.view' },
  '/settings/sales-groups': { perm: 'sales_group.view' },
  // Cégek közötti (superadmin) olvasó nézet. Szándékosan `perm` és NEM
  // `superadminOnly`: a kulcsot a PermissionChecker csak superadminnak adja meg
  // (SUPERADMIN_ONLY_KEYS), ÉS a modul-kapun is átmegy — kikapcsolt
  // `sales_group` modulnál a kulcs eltűnik, a menüpont/route vele együtt.
  '/settings/sales-groups/all': { perm: 'sales_group.view_cross_company' },
  '/settings/translations': { perm: 'company.manage' },
}

export function hasRouteAccess(access, { user, can, params } = {}) {
  if (!access || access.public) return true
  if (access.superadminOnly) return !!user?.is_superadmin
  if (access.check) return !!access.check({ user, can, params })
  if (access.anyPerm) return access.anyPerm.some((p) => can(p))
  if (access.perm) return can(access.perm)
  return false
}
