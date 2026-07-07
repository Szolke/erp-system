import { useState, useMemo, useCallback, useRef } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { Search, X } from 'lucide-react'
import { useTranslation } from '../contexts/TranslationContext'
import { getPage, getTitle, PAGE_COUNT, hrefToChapterIndex } from '../wiki'

// ── Helpers ──────────────────────────────────────────────────────────────────

function stripMarkdown(text) {
  return text
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/`[^`\n]+`/g, ' ')
    .replace(/^#{1,6}\s+/gm, '')
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/\*([^*]+)\*/g, '$1')
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    .replace(/^[|].*$/gm, ' ')
    .replace(/^[-*_]{3,}$/gm, ' ')
    .replace(/^\s*[-*+>]\s*/gm, ' ')
    .replace(/^\s*\d+\.\s*/gm, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function getSnippet(stripped, query, ctx = 65) {
  const lower = stripped.toLowerCase()
  const idx   = lower.indexOf(query.toLowerCase())
  if (idx === -1) return stripped.slice(0, ctx * 2) + '…'
  const start  = Math.max(0, idx - ctx)
  const end    = Math.min(stripped.length, idx + query.length + ctx)
  return (start > 0 ? '…' : '') + stripped.slice(start, end) + (end < stripped.length ? '…' : '')
}

function Highlight({ text, query }) {
  if (!query || !text) return <>{text}</>
  const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const parts   = text.split(new RegExp(`(${escaped})`, 'gi'))
  return (
    <>
      {parts.map((part, i) =>
        part.toLowerCase() === query.toLowerCase()
          ? <mark key={i} className="wiki-highlight">{part}</mark>
          : part
      )}
    </>
  )
}

// ── Main component ────────────────────────────────────────────────────────────

export default function WikiPage() {
  const { locale, t } = useTranslation()
  const [chapter, setChapter] = useState(0)
  const [query,   setQuery]   = useState('')
  const searchRef = useRef(null)

  const contentLocale = locale === 'en' ? 'en' : 'hu'

  const pages = useMemo(
    () => Array.from({ length: PAGE_COUNT }, (_, i) => getPage(contentLocale, i)),
    [contentLocale]
  )

  const titles  = useMemo(() => pages.map(getTitle), [pages])
  const content = pages[chapter] ?? ''

  // Stripped plain-text index for search (recomputed only on locale change)
  const searchIndex = useMemo(
    () => pages.map((raw, i) => ({ index: i, title: titles[i], stripped: stripMarkdown(raw) })),
    [pages, titles]
  )

  const trimmedQuery = query.trim()
  const isSearching  = trimmedQuery.length >= 2

  const searchResults = useMemo(() => {
    if (!isSearching) return []
    const q = trimmedQuery.toLowerCase()
    return searchIndex
      .filter(({ stripped, title }) =>
        stripped.toLowerCase().includes(q) || title.toLowerCase().includes(q)
      )
      .map(({ index, title, stripped }) => ({
        index,
        title,
        snippet: getSnippet(stripped, trimmedQuery),
      }))
  }, [isSearching, trimmedQuery, searchIndex])

  function selectChapter(idx) {
    setChapter(idx)
    setQuery('')
  }

  // Custom link renderer
  const LinkComponent = useCallback(
    ({ href, children }) => {
      if (!href) return <span>{children}</span>
      if (href.startsWith('http://') || href.startsWith('https://')) {
        return <a href={href} target="_blank" rel="noopener noreferrer">{children}</a>
      }
      const idx = hrefToChapterIndex(href, contentLocale)
      if (idx >= 0) {
        return (
          <a href="#" onClick={(e) => { e.preventDefault(); selectChapter(idx) }} style={{ cursor: 'pointer' }}>
            {children}
          </a>
        )
      }
      return <span style={{ color: 'var(--color-muted)', textDecoration: 'underline dotted' }}>{children}</span>
    },
    [contentLocale]
  )

  return (
    <div style={{ display: 'flex', gap: 0, alignItems: 'flex-start', height: 'calc(100vh - 48px)' }}>

      {/* Bal panel */}
      <nav style={{
        width: 260, flexShrink: 0,
        borderRight: '1px solid var(--color-border)',
        height: '100%', display: 'flex', flexDirection: 'column',
      }}>
        {/* Kereső */}
        <div style={{ padding: '10px 12px', borderBottom: '1px solid var(--color-border)', flexShrink: 0 }}>
          <div style={{ position: 'relative', display: 'flex', alignItems: 'center' }}>
            <Search size={13} style={{
              position: 'absolute', left: 8, color: 'var(--color-muted)', pointerEvents: 'none',
            }} />
            <input
              ref={searchRef}
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t('wiki.search_placeholder')}
              style={{
                width: '100%', padding: '6px 28px 6px 26px',
                fontSize: 12, border: '1px solid var(--color-border)', borderRadius: 5,
                background: 'var(--color-surface)', color: 'var(--color-text)',
                outline: 'none', boxSizing: 'border-box',
              }}
            />
            {query && (
              <button
                onClick={() => { setQuery(''); searchRef.current?.focus() }}
                style={{
                  position: 'absolute', right: 6, background: 'none', border: 'none',
                  cursor: 'pointer', color: 'var(--color-muted)', padding: 0, display: 'flex',
                }}
                title="Törlés"
              >
                <X size={12} />
              </button>
            )}
          </div>
        </div>

        {/* Fejezetlista vagy találatok */}
        <div style={{ flex: 1, overflowY: 'auto', paddingTop: 6, paddingBottom: 12 }}>
          {isSearching ? (
            searchResults.length === 0 ? (
              <p style={{ padding: '12px 16px', fontSize: 12, color: 'var(--color-muted)', margin: 0 }}>
                {t('wiki.no_results')}
              </p>
            ) : (
              searchResults.map(({ index, title, snippet }) => (
                <button
                  key={index}
                  onClick={() => selectChapter(index)}
                  style={{
                    width: '100%', textAlign: 'left',
                    padding: '9px 14px',
                    border: 'none', borderLeft: '3px solid transparent',
                    background: 'transparent',
                    cursor: 'pointer', display: 'block',
                  }}
                  onMouseEnter={(e) => e.currentTarget.style.background = 'var(--color-hover)'}
                  onMouseLeave={(e) => e.currentTarget.style.background = 'transparent'}
                >
                  <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--color-text)', marginBottom: 3 }}>
                    <Highlight text={title} query={trimmedQuery} />
                  </div>
                  <div style={{ fontSize: 11, color: 'var(--color-muted)', lineHeight: 1.45 }}>
                    <Highlight text={snippet} query={trimmedQuery} />
                  </div>
                </button>
              ))
            )
          ) : (
            titles.map((title, i) => (
              <button
                key={i}
                onClick={() => selectChapter(i)}
                title={title}
                style={{
                  width: '100%', textAlign: 'left',
                  padding: '9px 16px',
                  border: 'none',
                  borderLeft: `3px solid ${i === chapter ? 'var(--color-primary)' : 'transparent'}`,
                  background: i === chapter ? 'rgba(37,99,235,0.07)' : 'transparent',
                  color: i === chapter ? 'var(--color-primary)' : 'var(--color-text)',
                  cursor: 'pointer',
                  fontSize: 13, fontWeight: i === chapter ? 600 : 400,
                  lineHeight: 1.4, display: 'block',
                }}
              >
                {title}
              </button>
            ))
          )}
        </div>
      </nav>

      {/* Jobb panel */}
      <div style={{
        flex: 1, minWidth: 0,
        overflowY: 'auto',
        height: '100%',
        padding: '24px 32px',
      }}>
        <div className="wiki-content">
          <ReactMarkdown remarkPlugins={[remarkGfm]} components={{ a: LinkComponent }}>
            {content}
          </ReactMarkdown>
        </div>
      </div>
    </div>
  )
}
