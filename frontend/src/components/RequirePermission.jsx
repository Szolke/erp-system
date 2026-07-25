import { useParams } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { useTranslation } from '../contexts/TranslationContext'
import { hasRouteAccess } from '../routePermissions'

/**
 * Route-szintű jogosultság-védelem a lazy oldalak köré (l. App.jsx). Ha nincs
 * jog, a `children` HELYETT a tiltó nézetet adja vissza — a `React.lazy()`
 * betöltője emiatt el sem indul, a chunk nem töltődik le (ugyanaz a
 * mechanizmus, mint a `ProtectedRoute`-nál).
 *
 * Nem átirányít: a sidebar/Layout a helyén marad, a tartalom-területen jelenik
 * meg az üzenet — egy néma átirányítás megzavarná a mentett linkről érkezőt.
 */
export default function RequirePermission({ access, children }) {
  const { user, can } = useAuth()
  const { t } = useTranslation()
  const params = useParams()

  if (hasRouteAccess(access, { user, can, params })) return children

  return (
    <div className="route-error-boundary">
      <div className="alert-error">
        <p>{t('common.no_page_permission')}</p>
      </div>
    </div>
  )
}
