import { useRef, useState } from 'react'
import { Calendar } from 'lucide-react'
import { useTranslation } from '../../contexts/TranslationContext'
import { usePopoverDismiss } from '../../utils/usePopoverDismiss'

function ym(year, month) {
  return `${year}-${String(month + 1).padStart(2, '0')}`
}

function ymd(year, month, day) {
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function parseYm(str) {
  const [y, m] = str.split('-').map(Number)
  return { year: y, month: m - 1 }
}

function daysInMonth(year, month) {
  return new Date(year, month + 1, 0).getDate()
}

/** Hétfővel kezdődő hét (magyar konvenció) — JS getDay() Sun=0..Sat=6. */
function firstWeekdayOffset(year, month) {
  return (new Date(year, month, 1).getDay() + 6) % 7
}

function monthLabel(locale, year, month) {
  return new Intl.DateTimeFormat(locale, { month: 'short' }).format(new Date(year, month, 1))
}

function monthYearLabel(locale, year, month) {
  return new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'long' }).format(new Date(year, month, 1))
}

/** Hétfő-kezdetű rövid napnevek — 2024-01-01 egy hétfő, referenciának. */
function weekdayLabels(locale) {
  const fmt = new Intl.DateTimeFormat(locale, { weekday: 'short' })
  return Array.from({ length: 7 }, (_, i) => fmt.format(new Date(2024, 0, 1 + i)))
}

function formatRangeLabel(locale, from, to, unit, placeholder) {
  if (!from || !to) return placeholder
  if (unit === 'day') {
    const fmt = new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'short', day: 'numeric' })
    return `${fmt.format(new Date(from))} → ${fmt.format(new Date(to))}`
  }
  const f = parseYm(from)
  const t = parseYm(to)
  const fmt = new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'short' })
  return `${fmt.format(new Date(f.year, f.month, 1))} → ${fmt.format(new Date(t.year, t.month, 1))}`
}

/**
 * Egyetlen kontroll a dátum-tartomány kiválasztásához — SAJÁT komponens
 * (nincs új könyvtár telepítve), a Kimutatások (havi felbontás) ÉS a
 * Bizonylatok (napi felbontás) oldal is EZT hasznosítja újra, a `unit`
 * propon keresztül ('month' | 'day', alapértelmezés 'month').
 *
 * A kijelölés-logika (első kattintás = kezdet, második = vég, felcserélés,
 * ha a második korábbi) MINDKÉT módban ugyanaz, mert 'YYYY-MM' és
 * 'YYYY-MM-DD' stringek lexikografikus rendezése megegyezik az időrenddel —
 * csak a rács MEGJELENÍTÉSE (12 hónap/év vs. napok hete-hónapja) és a
 * navigáció (év-lépés vs. hónap-lépés) tér el a két mód közt.
 * A lekérdezés csak "Alkalmaz"-ra fut — a rácson kattintgatás önmagában
 * csak a helyi piszkozat-állapotot módosítja, amíg meg nem erősítik.
 */
export default function DateRangePicker({ from, to, onApply, open, onOpenChange, id, unit = 'month', placeholder, align = 'left' }) {
  const { t, locale } = useTranslation()
  const containerRef = useRef(null)
  const triggerRef = useRef(null)

  // draftFrom/draftTo lehet null: nincs (még) kiválasztott tartomány (pl. a
  // Bizonylatok oldal alapból nem szűr dátumra) — ilyenkor a rácson semmi
  // sincs előre kijelölve, az első kattintás indítja a valódi kijelölést.
  const [draftFrom, setDraftFrom] = useState(from || null)
  const [draftTo, setDraftTo] = useState(to || null)
  const [selecting, setSelecting] = useState(false)
  const [leftCursor, setLeftCursor] = useState(() => cursorFrom(from))
  const [rightCursor, setRightCursor] = useState(() => cursorFrom(to, 1))

  /** `monthOffset` csak akkor számít, ha nincs érték (napi módban a jobb
   *  rács alapból a következő hónapot mutassa, ne a bal rács duplikátumát). */
  function cursorFrom(value, monthOffset = 0) {
    if (unit === 'day') {
      const d = value ? new Date(value) : new Date()
      if (!value && monthOffset) d.setMonth(d.getMonth() + monthOffset)
      return { year: d.getFullYear(), month: d.getMonth() }
    }
    return { year: value ? parseYm(value).year : new Date().getFullYear(), month: 0 }
  }

  usePopoverDismiss(open, () => onOpenChange(false), containerRef, triggerRef)

  function handleToggle() {
    if (!open) {
      setDraftFrom(from || null)
      setDraftTo(to || null)
      setSelecting(false)
      setLeftCursor(cursorFrom(from))
      setRightCursor(cursorFrom(to, 1))
    }
    onOpenChange(!open)
  }

  function handlePick(value) {
    if (!selecting || draftFrom === null) {
      setDraftFrom(value)
      setDraftTo(value)
      setSelecting(true)
      return
    }
    if (value < draftFrom) {
      setDraftTo(draftFrom)
      setDraftFrom(value)
    } else {
      setDraftTo(value)
    }
    setSelecting(false)
  }

  function rangeLo() { return draftFrom < draftTo ? draftFrom : draftTo }
  function rangeHi() { return draftFrom < draftTo ? draftTo : draftFrom }
  function isEndpoint(value) { return draftFrom !== null && (value === draftFrom || value === draftTo) }
  function isInRange(value) { return draftFrom !== null && value >= rangeLo() && value <= rangeHi() }

  function handleCancel() {
    onOpenChange(false)
    triggerRef.current?.focus()
  }

  function handleApply() {
    if (draftFrom !== null) {
      onApply(rangeLo(), rangeHi())
    }
    onOpenChange(false)
    triggerRef.current?.focus()
  }

  function stepCursor(setCursor, direction) {
    setCursor((cursor) => {
      if (unit === 'day') {
        let { year, month } = cursor
        month += direction
        if (month < 0) { month = 11; year -= 1 }
        if (month > 11) { month = 0; year += 1 }
        return { year, month }
      }
      return { year: cursor.year + direction, month: 0 }
    })
  }

  function renderMonthGrid(cursor, setCursor, label) {
    return (
      <div className="fc-daterange-grid">
        <div className="fc-daterange-grid-header">
          <button type="button" className="fc-daterange-nav" onClick={() => stepCursor(setCursor, -1)} aria-label={t('reports.prev_year')}>‹</button>
          <span>{label} · {cursor.year}</span>
          <button type="button" className="fc-daterange-nav" onClick={() => stepCursor(setCursor, 1)} aria-label={t('reports.next_year')}>›</button>
        </div>
        <div className="fc-daterange-months">
          {Array.from({ length: 12 }, (_, month) => {
            const value = ym(cursor.year, month)
            return (
              <button
                key={month}
                type="button"
                className={'fc-daterange-month' + (isEndpoint(value) ? ' is-endpoint' : isInRange(value) ? ' is-in-range' : '')}
                onClick={() => handlePick(value)}
              >
                {monthLabel(locale, cursor.year, month)}
              </button>
            )
          })}
        </div>
      </div>
    )
  }

  function renderDayGrid(cursor, setCursor, label) {
    const total = daysInMonth(cursor.year, cursor.month)
    const offset = firstWeekdayOffset(cursor.year, cursor.month)

    return (
      <div className="fc-daterange-grid">
        <div className="fc-daterange-grid-header">
          <button type="button" className="fc-daterange-nav" onClick={() => stepCursor(setCursor, -1)} aria-label={t('reports.prev_month')}>‹</button>
          <span>{label} · {monthYearLabel(locale, cursor.year, cursor.month)}</span>
          <button type="button" className="fc-daterange-nav" onClick={() => stepCursor(setCursor, 1)} aria-label={t('reports.next_month')}>›</button>
        </div>
        <div className="fc-daterange-weekdays">
          {weekdayLabels(locale).map((w) => <span key={w}>{w}</span>)}
        </div>
        <div className="fc-daterange-days">
          {Array.from({ length: offset }, (_, i) => <span key={`pad-${i}`} />)}
          {Array.from({ length: total }, (_, i) => {
            const day = i + 1
            const value = ymd(cursor.year, cursor.month, day)
            return (
              <button
                key={day}
                type="button"
                className={'fc-daterange-day' + (isEndpoint(value) ? ' is-endpoint' : isInRange(value) ? ' is-in-range' : '')}
                onClick={() => handlePick(value)}
              >
                {day}
              </button>
            )
          })}
        </div>
      </div>
    )
  }

  const renderGrid = unit === 'day' ? renderDayGrid : renderMonthGrid

  return (
    <div className="fc-popover-root" ref={containerRef}>
      <button
        ref={triggerRef}
        type="button"
        className="fc-trigger-btn"
        onClick={handleToggle}
        aria-haspopup="dialog"
        aria-expanded={open}
        id={id}
      >
        <Calendar size={14} aria-hidden="true" />
        <span>{formatRangeLabel(locale, from, to, unit, placeholder ?? t('reports.filter_date_range'))}</span>
      </button>

      {open && (
        <div className={'fc-popover-panel fc-daterange-panel' + (unit === 'day' ? ' fc-daterange-panel--day' : '') + (align === 'right' ? ' fc-popover-panel--right' : '')} role="dialog" aria-label={t('reports.filter_date_range')}>
          <div className="fc-daterange-grids">
            {renderGrid(leftCursor, setLeftCursor, t('reports.range_start'))}
            {renderGrid(rightCursor, setRightCursor, t('reports.range_end'))}
          </div>
          <div className="fc-popover-footer">
            <button type="button" className="btn btn-secondary btn-sm" onClick={handleCancel}>{t('common.cancel')}</button>
            <button type="button" className="btn btn-primary btn-sm" onClick={handleApply}>{t('reports.apply')}</button>
          </div>
        </div>
      )}
    </div>
  )
}
