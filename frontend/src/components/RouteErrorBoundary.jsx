import { Component } from 'react'
import { useTranslation } from '../contexts/TranslationContext'

// A dinamikus `import()` hálózati/elavult-chunk hibáinak üzenete böngészőnként eltér,
// de mindegyik felismerhető: Vite/Rollup ("Failed to fetch dynamically imported module"),
// klasszikus webpack-stílusú üzenet ("Loading chunk X failed"), illetve Safari
// ("error loading dynamically imported module" / "Importing a module script failed").
// Csak EZEKRE ajánlunk újratöltést — minden más render-hiba általános üzenetet kap,
// mert azt egy oldal-újratöltés nem feltétlenül oldja meg (pl. programozási hiba).
const CHUNK_LOAD_ERROR_PATTERN = /failed to fetch dynamically imported module|loading chunk .* failed|error loading dynamically imported module|importing a module script failed/i

function isChunkLoadError(error) {
  return CHUNK_LOAD_ERROR_PATTERN.test(error?.message ?? '')
}

class RouteErrorBoundaryInner extends Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  render() {
    const { error } = this.state
    if (!error) return this.props.children

    const { t } = this.props
    const chunkError = isChunkLoadError(error)

    return (
      <div className="route-error-boundary">
        <div className="alert-error">
          <p>{chunkError ? t('common.chunk_load_error') : t('common.page_error')}</p>
        </div>
        {chunkError && (
          <button type="button" className="btn btn-secondary" onClick={() => window.location.reload()}>
            {t('common.retry')}
          </button>
        )}
      </div>
    )
  }
}

/**
 * Route-szintű hibahatár a lazy oldalak köré. A HÍVÓ FELELŐSSÉGE `key={pathname}`-nel
 * ellátni (l. Layout.jsx) — enélkül egy render-hiba után a felhasználó a hibaképernyőn
 * ragadna, mert az Error Boundary state-je navigációra önmagától nem törlődik.
 */
export default function RouteErrorBoundary({ children }) {
  const { t } = useTranslation()
  return <RouteErrorBoundaryInner t={t}>{children}</RouteErrorBoundaryInner>
}
