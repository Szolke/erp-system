import { useEffect, useState } from 'react'
import { useAuth } from '../../contexts/AuthContext'
import { useTranslation } from '../../contexts/TranslationContext'
import { useToast } from '../../contexts/ToastContext'
import { modules as modulesApi } from '../../api/modules'

export default function ModulesPage() {
  const { user, refreshAuth } = useAuth()
  const { t } = useTranslation()
  const toast = useToast()

  const [list, setList]       = useState([])
  const [loading, setLoading] = useState(true)
  const [toggling, setToggling] = useState(null) // key of the module currently being toggled

  async function load() {
    setLoading(true)
    try {
      const res = await modulesApi.list()
      setList(res.data.data ?? [])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  async function handleToggle(mod) {
    if (mod.is_core || toggling !== null) return
    const enabling = !mod.enabled
    setToggling(mod.key)
    try {
      await modulesApi.update(mod.key, { enabled: enabling })
      toast(`„${mod.name}" ${enabling ? 'bekapcsolva' : 'kikapcsolva'}`, 'success')
      await refreshAuth()
      load()
    } catch (err) {
      const body = err.response?.data
      let msg
      if (enabling && body?.missing_dependencies?.length) {
        const names = body.missing_dependencies.map((k) => list.find((m) => m.key === k)?.name ?? k).join(', ')
        msg = `Bekapcsoláshoz szükséges: ${names}`
      } else if (!enabling && body?.dependents?.length) {
        const names = body.dependents.map((k) => list.find((m) => m.key === k)?.name ?? k).join(', ')
        msg = `Nem kapcsolható ki, mert függ tőle: ${names}`
      } else {
        msg = body?.message ?? t('common.error')
      }
      toast(msg, 'error')
    } finally {
      setToggling(null)
    }
  }

  if (!user?.is_superadmin) {
    return <p className="text-muted">{t('common.no_permission')}</p>
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Modulok</h1>
      </div>
      <p className="text-muted" style={{ marginBottom: 20, fontSize: 13 }}>
        A modulok be- és kikapcsolása az aktuális cégre vonatkozik. Az alap modulok nem kapcsolhatók ki.
      </p>

      {loading ? (
        <p className="text-muted">{t('common.loading')}</p>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))', gap: 16 }}>
          {list.map((mod) => (
            <ModuleCard
              key={mod.key}
              mod={mod}
              onToggle={handleToggle}
              isToggling={toggling === mod.key}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function ModuleCard({ mod, onToggle, isToggling }) {
  return (
    <div className="card" style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
            <strong style={{ fontSize: 14 }}>{mod.name}</strong>
            <span className="badge badge-inv-issued" style={{ fontSize: 10 }}>v{mod.version}</span>
            {mod.is_core && (
              <span className="badge badge-pay-paid" style={{ fontSize: 10 }}>Mindig aktív</span>
            )}
          </div>
          {mod.description && (
            <p className="text-muted" style={{ fontSize: 12, margin: '4px 0 0', lineHeight: 1.4 }}>
              {mod.description}
            </p>
          )}
        </div>
        <ToggleSwitch
          enabled={mod.enabled}
          disabled={mod.is_core}
          loading={isToggling}
          onClick={() => onToggle(mod)}
        />
      </div>
      {mod.dependencies?.length > 0 && (
        <p style={{ fontSize: 11, color: 'var(--color-muted)', margin: 0 }}>
          Függőség:{' '}
          {mod.dependencies.map((dep, i) => (
            <span key={dep}>
              {i > 0 && ', '}
              <code style={{ fontSize: 11 }}>{dep}</code>
            </span>
          ))}
        </p>
      )}
    </div>
  )
}

function ToggleSwitch({ enabled, disabled, loading, onClick }) {
  return (
    <button
      onClick={onClick}
      disabled={disabled || loading}
      title={disabled ? 'Alap modul — nem kapcsolható ki' : (enabled ? 'Kikapcsolás' : 'Bekapcsolás')}
      style={{
        flexShrink: 0,
        width: 44,
        height: 24,
        borderRadius: 12,
        border: 'none',
        cursor: disabled || loading ? 'not-allowed' : 'pointer',
        background: enabled
          ? (disabled ? 'var(--color-primary-subtle, #bfdbfe)' : 'var(--color-primary, #3b82f6)')
          : 'var(--color-border, #d1d5db)',
        position: 'relative',
        transition: 'background 0.2s',
        opacity: disabled ? 0.55 : 1,
        padding: 0,
      }}
    >
      <span style={{
        position: 'absolute',
        top: 3,
        left: enabled ? 23 : 3,
        width: 18,
        height: 18,
        borderRadius: '50%',
        background: '#fff',
        transition: 'left 0.2s',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: 9,
        color: '#888',
        lineHeight: 1,
      }}>
        {loading ? '…' : ''}
      </span>
    </button>
  )
}
