import { useTranslation } from '../contexts/TranslationContext'
import { formatTimestamp } from '../utils/format'

/**
 * Diszkrét lábléc a törzsadat detail/edit oldalak alján: ki hozta létre és ki
 * módosította utoljára a rekordot.
 *
 * A backend-szerződést (app/Models/Concerns/HasBlameable.php) tükrözi. Egy
 * blame-mező NÉGY állapotot vehet fel, és mind a négyre más a helyes kimenet:
 *
 *   undefined  → a kulcs NINCS a válaszban. Két eset adja: (a) még nem mentett,
 *                új rekord, amihez nincs szerver-oldali adat, (b) lista-válasz,
 *                ahol a Resource whenLoaded() kapuja kihagyja a mezőt.
 *                Mindkettőnél a sor teljesen kimarad — NEM "—", hanem semmi.
 *   null       → a kulcs ott van, de az FK null: seeder / konzol / queue írta a
 *                sort, nincs mögötte felhasználó → "Rendszer". Időpont ilyenkor
 *                nem jön a backendtől, tehát nem is jelenítünk meg.
 *   { name, at }        → feloldott felhasználó.
 *   { name: null, at }  → az FK megvan, de a user sora már nincs (adatimport
 *                         vagy kézi DB-művelet után) → "—" + időpont. A mező
 *                         sosem marad üresen vagy törötten.
 *
 * Ha MINDKÉT mező undefined, a komponens nem renderel semmit.
 */
function BlameLine({ label, entry, t }) {
  // Kulcs hiányzik → nincs mit mondani (l. a fenti négy állapotot).
  if (entry === undefined) return null

  // FK null → rendszer hozta létre, időpont nélkül.
  if (entry === null) {
    return <span>{label}: {t('blame.system')}</span>
  }

  // A törölt user neve null — ilyenkor semleges jelölő áll a név helyén.
  const who  = entry.name ?? t('blame.unknown_user')
  const when = formatTimestamp(entry.at)

  return <span>{when ? `${label}: ${who} · ${when}` : `${label}: ${who}`}</span>
}

export default function BlameFooter({ createdBy, updatedBy, style }) {
  const { t } = useTranslation()

  if (createdBy === undefined && updatedBy === undefined) return null

  return (
    <div
      className="text-muted"
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        gap: '4px 20px',
        fontSize: 12,
        marginTop: 16,
        paddingTop: 10,
        borderTop: '1px solid var(--color-border)',
        ...style,
      }}
    >
      <BlameLine label={t('blame.created_by')} entry={createdBy} t={t} />
      <BlameLine label={t('blame.updated_by')} entry={updatedBy} t={t} />
    </div>
  )
}
