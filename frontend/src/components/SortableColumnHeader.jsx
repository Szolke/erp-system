import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react'
import { useTranslation } from '../contexts/TranslationContext'

/**
 * Listafüggetlen, rendezhető oszlopfejléc — a `useListColumns` hook `sort` /
 * `toggleSort` párjával együtt bármely listanézetben újrahasználható (jelenleg
 * a Bizonylatok listája használja, pilotként).
 *
 * A komponens SZÁNDÉKOSAN nem ismeri a rendezési ciklust: csak jelenti, hogy a
 * fejlécre kattintottak (`onSort`), az "növekvő → csökkenő → alapértelmezett"
 * szabályt a hook alkalmazza — ugyanaz az elv, mint a `ColumnPicker` drag&drop
 * `onReorder`-jénél (a komponens az interakciót közli, az állapotszabályt a hook
 * tartja egy helyen).
 *
 * Akadálymentesség: a rendezés állapotát a `<th aria-sort>` közli (ez a
 * táblázatokra szabott, szabványos attribútum — képernyőolvasók magukban
 * bemondják), ezért a gomb feliratának nem kell az irányt is elismételnie; a
 * gomb `aria-label`-je csak a MŰVELETET nevezi meg. A `direction === null`
 * (alapértelmezett rendezés) `aria-sort="none"`-ra képződik le.
 *
 * A `<th>`-t maga a komponens rendereli (nem csak a belsejét), mert az
 * `aria-sort` és az igazítás a cellára tartozik — így a hívó oldalon egy
 * rendezhető és egy sima fejléc cseréje egyetlen ternáris.
 *
 * @param {string} label            már lefordított oszlopnév
 * @param {'asc'|'desc'|null} direction  az AKTUÁLIS rendezés iránya ezen az oszlopon
 * @param {() => void} onSort
 * @param {'right'|undefined} align
 */
export default function SortableColumnHeader({ label, direction = null, onSort, align }) {
  const { t } = useTranslation()

  const ariaSort = direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none'
  const Icon = direction === 'asc' ? ArrowUp : direction === 'desc' ? ArrowDown : ChevronsUpDown

  return (
    <th scope="col" aria-sort={ariaSort} className={align === 'right' ? 'text-right' : undefined}>
      <button
        type="button"
        className={
          'sortable-th'
          + (align === 'right' ? ' sortable-th--right' : '')
          + (direction ? ' is-active' : '')
        }
        onClick={onSort}
        aria-label={t('columns.sort_button', { name: label })}
      >
        <span>{label}</span>
        <Icon size={13} className="sortable-th-icon" aria-hidden="true" />
      </button>
    </th>
  )
}
