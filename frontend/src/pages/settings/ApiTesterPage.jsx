import { useEffect, useState } from 'react'
import { ChevronDown, ChevronRight, AlertTriangle } from 'lucide-react'
import { apiTester } from '../../api/apiTester'
import { useTranslation } from '../../contexts/TranslationContext'
import { useAuth } from '../../contexts/AuthContext'

const METHOD_STYLE = {
  get:    { bg: 'rgba(37,99,235,0.12)',  text: '#2563eb', label: 'GET' },
  post:   { bg: 'rgba(22,163,74,0.12)',  text: '#16a34a', label: 'POST' },
  put:    { bg: 'rgba(217,119,6,0.12)',  text: '#d97706', label: 'PUT' },
  patch:  { bg: 'rgba(217,119,6,0.12)',  text: '#d97706', label: 'PATCH' },
  delete: { bg: 'rgba(220,38,38,0.12)', text: '#dc2626', label: 'DELETE' },
}

const WRITE_METHODS = new Set(['post', 'put', 'patch', 'delete'])
const HTTP_METHODS  = ['get', 'post', 'put', 'patch', 'delete']

const DESTRUCTIVE_PATH_SEGMENTS = ['cancel', 'refund', 'regenerate-pdf']

function isDestructiveEndpoint(method, path) {
  if (method === 'delete') return true
  return DESTRUCTIVE_PATH_SEGMENTS.some(seg => path.includes(`/${seg}`))
}

function MethodBadge({ method }) {
  const s = METHOD_STYLE[method] ?? { bg: 'rgba(100,116,139,0.12)', text: '#64748b', label: method.toUpperCase() }
  return (
    <span style={{
      background: s.bg, color: s.text,
      fontFamily: 'monospace', fontWeight: 700, fontSize: 11,
      padding: '2px 7px', borderRadius: 4, whiteSpace: 'nowrap', flexShrink: 0,
    }}>
      {s.label}
    </span>
  )
}

function buildGroups(paths) {
  const map = new Map()
  for (const [path, methods] of Object.entries(paths ?? {})) {
    for (const method of HTTP_METHODS) {
      const op = methods[method]
      if (!op) continue
      const tag = op.tags?.[0] ?? 'Other'
      if (!map.has(tag)) map.set(tag, [])
      map.get(tag).push({ method, path, op })
    }
  }
  return Array.from(map.entries()).map(([tag, endpoints]) => ({ tag, endpoints }))
}

function buildBodyTemplate(schema) {
  if (!schema?.properties) return '{}'
  const obj = {}
  for (const [key, prop] of Object.entries(schema.properties)) {
    if (prop.type === 'string') obj[key] = ''
    else if (prop.type === 'number' || prop.type === 'integer') obj[key] = 0
    else if (prop.type === 'boolean') obj[key] = false
    else if (prop.type === 'array') obj[key] = []
    else obj[key] = null
  }
  return JSON.stringify(obj, null, 2)
}

function ParamTable({ params, t }) {
  if (!params?.length) return <p className="text-muted" style={{ fontSize: 13 }}>{t('api_tester.no_params')}</p>
  return (
    <table style={{ fontSize: 13 }}>
      <thead>
        <tr>
          <th style={{ width: 160 }}>{t('api_tester.param_name')}</th>
          <th style={{ width: 60 }}>{t('api_tester.param_in')}</th>
          <th style={{ width: 80 }}>{t('api_tester.param_type')}</th>
          <th style={{ width: 60 }}>{t('api_tester.required')}</th>
          <th>{t('api_tester.param_desc')}</th>
        </tr>
      </thead>
      <tbody>
        {params.map((p, i) => (
          <tr key={i}>
            <td style={{ fontFamily: 'monospace' }}>{p.name}</td>
            <td style={{ color: 'var(--color-muted)' }}>{p.in}</td>
            <td style={{ color: 'var(--color-muted)' }}>{p.schema?.type ?? '—'}</td>
            <td>{p.required ? <span style={{ color: 'var(--color-danger)', fontWeight: 600 }}>✓</span> : <span className="text-muted">—</span>}</td>
            <td style={{ color: 'var(--color-muted)' }}>{p.description ?? '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function BodySchema({ schema, t }) {
  if (!schema) return <p className="text-muted" style={{ fontSize: 13 }}>{t('api_tester.no_body_schema')}</p>
  const props = schema.properties ?? {}
  const required = new Set(schema.required ?? [])
  const entries = Object.entries(props)
  if (!entries.length) return (
    <pre style={{ fontSize: 12, background: 'var(--color-surface-alt)', padding: 10, borderRadius: 6, overflow: 'auto' }}>
      {JSON.stringify(schema, null, 2)}
    </pre>
  )
  return (
    <table style={{ fontSize: 13 }}>
      <thead>
        <tr>
          <th style={{ width: 160 }}>{t('api_tester.param_name')}</th>
          <th style={{ width: 80 }}>{t('api_tester.param_type')}</th>
          <th style={{ width: 60 }}>{t('api_tester.required')}</th>
          <th>{t('api_tester.param_desc')}</th>
        </tr>
      </thead>
      <tbody>
        {entries.map(([name, prop]) => (
          <tr key={name}>
            <td style={{ fontFamily: 'monospace' }}>{name}</td>
            <td style={{ color: 'var(--color-muted)' }}>{prop.type ?? '—'}</td>
            <td>{required.has(name) ? <span style={{ color: 'var(--color-danger)', fontWeight: 600 }}>✓</span> : <span className="text-muted">—</span>}</td>
            <td style={{ color: 'var(--color-muted)' }}>{prop.description ?? '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function EndpointDetail({ item, t }) {
  if (!item) return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: 120, color: 'var(--color-muted)' }}>
      <p>{t('api_tester.no_endpoint')}</p>
    </div>
  )

  const { method, path, op } = item
  const isWrite = WRITE_METHODS.has(method)
  const bodySchema = op.requestBody?.content?.['application/json']?.schema

  return (
    <div style={{ padding: '0 4px' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12, flexWrap: 'wrap' }}>
        <MethodBadge method={method} />
        <code style={{
          fontSize: 14, fontWeight: 600,
          background: 'var(--color-surface-alt)', padding: '4px 10px', borderRadius: 5,
          color: 'var(--color-text)',
        }}>{path}</code>
        {isWrite && (
          <span style={{
            fontSize: 11, padding: '2px 8px', borderRadius: 4,
            background: 'var(--color-warning-bg)', color: 'var(--color-warning)',
            border: '1px solid var(--color-warning)', fontWeight: 600,
          }}>
            ⚠ {t('api_tester.modifying_op')}
          </span>
        )}
      </div>

      {(op.summary || op.description) && (
        <div className="card" style={{ marginBottom: 12, padding: '12px 16px' }}>
          {op.summary && <p style={{ fontWeight: 600, marginBottom: op.description ? 4 : 0 }}>{op.summary}</p>}
          {op.description && <p style={{ fontSize: 13, color: 'var(--color-muted)', margin: 0 }}>{op.description}</p>}
        </div>
      )}

      <div className="card" style={{ marginBottom: 12, padding: '12px 16px' }}>
        <p style={{ fontWeight: 600, marginBottom: 10, fontSize: 12, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--color-muted)' }}>
          {t('api_tester.params')}
        </p>
        <ParamTable params={op.parameters} t={t} />
      </div>

      {isWrite && (
        <div className="card" style={{ marginBottom: 12, padding: '12px 16px' }}>
          <p style={{ fontWeight: 600, marginBottom: 10, fontSize: 12, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--color-muted)' }}>
            {t('api_tester.request_body')}
          </p>
          <BodySchema schema={bodySchema} t={t} />
        </div>
      )}
    </div>
  )
}

function ConfirmDialog({ open, method, resolvedPath, isDestructive, companyName, onConfirm, onCancel, sending, t }) {
  const [ackChecked, setAckChecked] = useState(false)

  useEffect(() => { if (open) setAckChecked(false) }, [open])

  if (!open) return null

  const canSend = !isDestructive || ackChecked

  return (
    <div
      style={{
        position: 'fixed', inset: 0,
        background: 'rgba(0,0,0,0.45)', zIndex: 1000,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
      }}
      onClick={onCancel}
    >
      <div
        className="card"
        style={{
          width: 460, padding: 24,
          ...(isDestructive ? { border: '2px solid var(--color-danger)' } : {}),
        }}
        onClick={e => e.stopPropagation()}
      >
        {isDestructive ? (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
            <AlertTriangle size={18} style={{ color: 'var(--color-danger)', flexShrink: 0 }} />
            <p style={{ fontWeight: 700, fontSize: 15, color: 'var(--color-danger)', margin: 0 }}>
              {t('api_tester.destructive_title')}
            </p>
          </div>
        ) : (
          <p style={{ fontWeight: 700, marginBottom: 6, fontSize: 15 }}>{t('api_tester.confirm_title')}</p>
        )}

        {isDestructive ? (
          <div style={{
            background: 'var(--color-danger-bg)', color: 'var(--color-danger)',
            border: '1px solid var(--color-danger)',
            borderRadius: 6, padding: '10px 12px', marginBottom: 14, fontSize: 13, lineHeight: 1.5,
          }}>
            {t('api_tester.destructive_warning')}
            {companyName && (
              <div style={{ marginTop: 6, fontWeight: 600 }}>
                {t('api_tester.destructive_company')} {companyName}
              </div>
            )}
          </div>
        ) : (
          <p style={{ fontSize: 13, color: 'var(--color-muted)', marginBottom: 16 }}>
            {t('api_tester.confirm_body')}
          </p>
        )}

        <div style={{
          display: 'flex', alignItems: 'center', gap: 8,
          padding: '8px 12px', background: 'var(--color-surface-alt)',
          borderRadius: 6, marginBottom: isDestructive ? 16 : 20,
        }}>
          <MethodBadge method={method} />
          <code style={{ fontSize: 13, color: 'var(--color-text)', wordBreak: 'break-all' }}>{resolvedPath}</code>
        </div>

        {isDestructive && (
          <label style={{
            display: 'flex', alignItems: 'flex-start', gap: 8,
            fontSize: 13, cursor: 'pointer', marginBottom: 20, lineHeight: 1.4,
          }}>
            <input
              type="checkbox"
              checked={ackChecked}
              onChange={e => setAckChecked(e.target.checked)}
              style={{ marginTop: 2, flexShrink: 0, accentColor: 'var(--color-danger)' }}
            />
            <span style={{ color: 'var(--color-text)' }}>{t('api_tester.destructive_ack')}</span>
          </label>
        )}

        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button
            onClick={onCancel}
            disabled={sending}
            style={{
              padding: '6px 14px', borderRadius: 5,
              border: '1px solid var(--color-border)',
              background: 'var(--color-surface)', color: 'var(--color-text)',
              cursor: 'pointer', fontSize: 13,
            }}
          >
            {t('api_tester.cancel')}
          </button>
          <button
            onClick={onConfirm}
            disabled={sending || !canSend}
            style={{
              padding: '6px 14px', borderRadius: 5, border: 'none',
              background: 'var(--color-danger)', color: '#fff',
              cursor: (sending || !canSend) ? 'not-allowed' : 'pointer',
              fontSize: 13, fontWeight: 600, minWidth: 80,
              opacity: (sending || !canSend) ? 0.45 : 1,
              transition: 'opacity 0.15s',
            }}
          >
            {sending ? t('api_tester.sending') : t('api_tester.send')}
          </button>
        </div>
      </div>
    </div>
  )
}

function ResponsePanel({ response, t }) {
  if (!response) return (
    <p className="text-muted" style={{ fontSize: 13 }}>{t('api_tester.no_response')}</p>
  )
  const isOk = response.status >= 200 && response.status < 300
  return (
    <div>
      <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 8 }}>
        <span style={{ fontWeight: 700, fontSize: 14, color: isOk ? '#16a34a' : '#dc2626' }}>
          HTTP {response.status || '—'}
        </span>
        <span style={{ fontSize: 12, color: 'var(--color-muted)' }}>{response.duration} ms</span>
      </div>
      <pre style={{
        fontSize: 12, lineHeight: 1.5, margin: 0,
        background: 'var(--color-surface-alt)',
        padding: '10px 12px', borderRadius: 6,
        overflow: 'auto', maxHeight: 360,
        border: `1px solid ${isOk ? 'var(--color-border)' : 'rgba(220,38,38,0.3)'}`,
        color: 'var(--color-text)',
      }}>
        {JSON.stringify(response.data, null, 2)}
      </pre>
    </div>
  )
}

export default function ApiTesterPage() {
  const { t } = useTranslation()
  const { companies, activeCompanyId } = useAuth()
  const activeCompany = companies.find(c => c.id === activeCompanyId)

  const [loading, setLoading]       = useState(true)
  const [error, setError]           = useState('')
  const [groups, setGroups]         = useState([])
  const [openGroups, setOpenGroups] = useState({})
  const [selected, setSelected]     = useState(null)

  // Form + send state
  const [pathValues, setPathValues]   = useState({})
  const [queryValues, setQueryValues] = useState({})
  const [bodyText, setBodyText]       = useState('{}')
  const [bodyError, setBodyError]     = useState('')
  const [sending, setSending]         = useState(false)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [apiResponse, setApiResponse] = useState(null)

  useEffect(() => { load() }, [])

  useEffect(() => {
    if (!selected) return
    const pathParams = [...selected.path.matchAll(/\{(\w+)\}/g)].map(m => m[1])
    setPathValues(Object.fromEntries(pathParams.map(p => [p, ''])))
    const queryParams = (selected.op.parameters ?? []).filter(p => p.in === 'query')
    setQueryValues(Object.fromEntries(queryParams.map(p => [p.name, ''])))
    const schema = selected.op.requestBody?.content?.['application/json']?.schema
    setBodyText(buildBodyTemplate(schema))
    setApiResponse(null)
    setBodyError('')
  }, [selected])

  async function load() {
    setLoading(true)
    setError('')
    try {
      const res = await apiTester.getSpec()
      const g = buildGroups(res.data.paths)
      setGroups(g)
      setOpenGroups(Object.fromEntries(g.map((grp) => [grp.tag, true])))
    } catch (err) {
      const status = err.response?.status
      setError(status === 503 ? t('api_tester.no_spec') : (err.response?.data?.message ?? t('common.error')))
    } finally {
      setLoading(false)
    }
  }

  function toggleGroup(tag) {
    setOpenGroups((prev) => ({ ...prev, [tag]: !prev[tag] }))
  }

  function resolveUrl() {
    if (!selected) return ''
    return selected.path.replace(/\{(\w+)\}/g, (_, k) => pathValues[k] || `{${k}}`)
  }

  async function handleSend() {
    if (!selected) return
    if (WRITE_METHODS.has(selected.method)) {
      try { JSON.parse(bodyText) } catch {
        setBodyError(t('api_tester.body_json_error'))
        return
      }
      setConfirmOpen(true)
      return
    }
    await doSend()
  }

  async function doSend() {
    if (!selected) return
    setConfirmOpen(false)
    setSending(true)
    setApiResponse(null)
    const start = Date.now()
    try {
      const url    = resolveUrl()
      const params = Object.fromEntries(Object.entries(queryValues).filter(([, v]) => v !== ''))
      const data   = WRITE_METHODS.has(selected.method) ? JSON.parse(bodyText) : undefined
      const res    = await apiTester.send(selected.method, url, { params, data })
      setApiResponse({ status: res.status, data: res.data, duration: Date.now() - start })
    } catch (err) {
      setApiResponse({
        status: err.response?.status ?? 0,
        data: err.response?.data ?? { message: err.message },
        duration: Date.now() - start,
        isError: true,
      })
    } finally {
      setSending(false)
    }
  }

  const totalEndpoints = groups.reduce((s, g) => s + g.endpoints.length, 0)

  const inputStyle = {
    flex: 1, fontSize: 13, padding: '5px 8px',
    border: '1px solid var(--color-border)', borderRadius: 4,
    background: 'var(--color-surface)', color: 'var(--color-text)',
  }

  return (
    <div>
      <ConfirmDialog
        open={confirmOpen}
        method={selected?.method}
        resolvedPath={resolveUrl()}
        isDestructive={selected ? isDestructiveEndpoint(selected.method, selected.path) : false}
        companyName={activeCompany?.name}
        onConfirm={doSend}
        onCancel={() => setConfirmOpen(false)}
        sending={sending}
        t={t}
      />

      <div className="page-header">
        <h1 className="page-title">{t('api_tester.title')}</h1>
        {!loading && !error && (
          <span className="text-muted" style={{ fontSize: 13 }}>
            {totalEndpoints} endpoint · {groups.length} {t('api_tester.groups')}
          </span>
        )}
      </div>

      {loading && <p className="text-muted">{t('api_tester.loading')}</p>}

      {error && (
        <div className="card">
          <p style={{ color: 'var(--color-danger)' }}>{error}</p>
        </div>
      )}

      {!loading && !error && (
        <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>

          {/* Bal panel: endpoint-lista */}
          <div style={{
            width: 300, flexShrink: 0,
            border: '1px solid var(--color-border)', borderRadius: 8,
            background: 'var(--color-surface)',
            maxHeight: 'calc(100vh - 140px)', overflowY: 'auto',
          }}>
            {groups.map((grp) => (
              <div key={grp.tag}>
                <button
                  onClick={() => toggleGroup(grp.tag)}
                  style={{
                    width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                    padding: '9px 14px', background: 'var(--color-surface-alt)',
                    border: 'none', borderBottom: '1px solid var(--color-border)',
                    cursor: 'pointer', color: 'var(--color-text)', fontWeight: 600, fontSize: 12,
                    textTransform: 'uppercase', letterSpacing: '.05em', textAlign: 'left',
                  }}
                >
                  <span>{grp.tag}</span>
                  <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <span style={{ color: 'var(--color-muted)', fontWeight: 400 }}>{grp.endpoints.length}</span>
                    {openGroups[grp.tag]
                      ? <ChevronDown size={13} style={{ color: 'var(--color-muted)' }} />
                      : <ChevronRight size={13} style={{ color: 'var(--color-muted)' }} />}
                  </span>
                </button>

                {openGroups[grp.tag] && grp.endpoints.map((ep, i) => {
                  const isActive = selected?.method === ep.method && selected?.path === ep.path
                  return (
                    <button
                      key={i}
                      onClick={() => setSelected(ep)}
                      style={{
                        width: '100%', display: 'flex', alignItems: 'center', gap: 8,
                        padding: '7px 14px', border: 'none', borderBottom: '1px solid var(--color-border)',
                        background: isActive ? 'rgba(37,99,235,0.07)' : 'var(--color-surface)',
                        cursor: 'pointer', textAlign: 'left',
                        borderLeft: isActive ? '3px solid var(--color-primary)' : '3px solid transparent',
                      }}
                    >
                      <MethodBadge method={ep.method} />
                      <span style={{
                        fontSize: 12, color: 'var(--color-text)',
                        overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
                        fontFamily: 'monospace',
                      }} title={ep.path}>
                        {ep.path.replace('/api/', '')}
                      </span>
                    </button>
                  )
                })}
              </div>
            ))}
          </div>

          {/* Jobb panel: részletek + form + válasz */}
          <div style={{ flex: 1, minWidth: 0 }}>
            <EndpointDetail item={selected} t={t} />

            {selected && (
              <>
                {/* Kérés küldése */}
                <div className="card" style={{ marginTop: 12, padding: '14px 16px' }}>
                  <p style={{ fontWeight: 600, marginBottom: 14, fontSize: 12, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--color-muted)' }}>
                    {t('api_tester.send_request')}
                  </p>

                  {Object.keys(pathValues).length > 0 && (
                    <div style={{ marginBottom: 14 }}>
                      <p style={{ fontSize: 12, fontWeight: 600, marginBottom: 8, color: 'var(--color-text)' }}>
                        {t('api_tester.path_params')}
                      </p>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                        {Object.keys(pathValues).map(key => (
                          <div key={key} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <code style={{ fontSize: 12, minWidth: 110, color: 'var(--color-muted)' }}>{`{${key}}`}</code>
                            <input
                              type="text"
                              value={pathValues[key]}
                              onChange={e => setPathValues(prev => ({ ...prev, [key]: e.target.value }))}
                              placeholder={key}
                              style={inputStyle}
                            />
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {Object.keys(queryValues).length > 0 && (
                    <div style={{ marginBottom: 14 }}>
                      <p style={{ fontSize: 12, fontWeight: 600, marginBottom: 8, color: 'var(--color-text)' }}>
                        {t('api_tester.query_params')}
                      </p>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                        {Object.keys(queryValues).map(key => (
                          <div key={key} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <code style={{ fontSize: 12, minWidth: 110, color: 'var(--color-muted)' }}>{key}</code>
                            <input
                              type="text"
                              value={queryValues[key]}
                              onChange={e => setQueryValues(prev => ({ ...prev, [key]: e.target.value }))}
                              placeholder={key}
                              style={inputStyle}
                            />
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {WRITE_METHODS.has(selected.method) && (
                    <div style={{ marginBottom: 14 }}>
                      <p style={{ fontSize: 12, fontWeight: 600, marginBottom: 8, color: 'var(--color-text)' }}>
                        {t('api_tester.body_json')}
                      </p>
                      <textarea
                        value={bodyText}
                        onChange={e => { setBodyText(e.target.value); setBodyError('') }}
                        rows={8}
                        style={{
                          width: '100%', fontSize: 12, fontFamily: 'monospace', lineHeight: 1.5,
                          padding: '8px 10px', borderRadius: 4, boxSizing: 'border-box',
                          border: `1px solid ${bodyError ? '#dc2626' : 'var(--color-border)'}`,
                          background: 'var(--color-surface)', color: 'var(--color-text)',
                          resize: 'vertical',
                        }}
                      />
                      {bodyError && (
                        <p style={{ fontSize: 12, color: '#dc2626', marginTop: 4 }}>{bodyError}</p>
                      )}
                    </div>
                  )}

                  <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                    <button
                      onClick={handleSend}
                      disabled={sending}
                      style={{
                        padding: '7px 20px', borderRadius: 5, border: 'none',
                        background: 'var(--color-primary, #2563eb)', color: '#fff',
                        cursor: sending ? 'not-allowed' : 'pointer',
                        fontSize: 13, fontWeight: 600, minWidth: 90,
                        opacity: sending ? 0.7 : 1,
                      }}
                    >
                      {sending ? t('api_tester.sending') : t('api_tester.send')}
                    </button>
                  </div>
                </div>

                {/* Válasz */}
                <div className="card" style={{ marginTop: 12, padding: '14px 16px' }}>
                  <p style={{ fontWeight: 600, marginBottom: 12, fontSize: 12, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--color-muted)' }}>
                    {t('api_tester.response_section')}
                  </p>
                  <ResponsePanel response={apiResponse} t={t} />
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
