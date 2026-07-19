import { useState } from 'react'
import { Download } from 'lucide-react'
import { useToast } from '../contexts/ToastContext'

/**
 * Általános "letöltés" gomb CSV-exporthoz — a Kimutatások és a Bizonylatok
 * oldal egyaránt ezt használja (nincs másodpéldány). A hívó dönti el, mit
 * jelent a láthatóság (`visible`), hogyan éri el az adatot (`onExport`, a
 * blob-válasz Promise-a), és mi legyen a letöltött fájl neve (`filename`) —
 * a komponens csak a letöltés-mechanikát (blob-URL, letiltás töltés közben,
 * hiba-toast) és a gomb stílusát adja.
 */
export default function ExportButton({ visible, onExport, filename, label, exportingLabel, errorLabel, className = 'btn btn-secondary' }) {
  const toast = useToast()
  const [downloading, setDownloading] = useState(false)

  if (!visible) return null

  async function handleExport() {
    setDownloading(true)
    try {
      const res = await onExport()
      const url = URL.createObjectURL(res.data)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      toast(errorLabel, 'error')
    } finally {
      setDownloading(false)
    }
  }

  return (
    <button className={className} onClick={handleExport} disabled={downloading}>
      <Download size={14} />
      {downloading ? exportingLabel : label}
    </button>
  )
}
