function hexLuminance(hex) {
  const r = parseInt(hex.slice(1, 3), 16) / 255
  const g = parseInt(hex.slice(3, 5), 16) / 255
  const b = parseInt(hex.slice(5, 7), 16) / 255
  const lin = (c) => c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}

function mixHex(base, target, basePct) {
  const b = [parseInt(base.slice(1, 3), 16), parseInt(base.slice(3, 5), 16), parseInt(base.slice(5, 7), 16)]
  const t = (target === '#000' || target === '#000000') ? [0, 0, 0] : [255, 255, 255]
  const p = basePct / 100
  return '#' + b.map((c, i) => Math.round(c * p + t[i] * (1 - p)).toString(16).padStart(2, '0')).join('')
}

/**
 * Sets --sidebar-accent-color, --sidebar-text-color, and --sidebar-hover-bg
 * on :root so the sidebar CSS variables reflect the chosen accent color.
 *
 * Text color threshold (WCAG): L < 0.179 → white, else #1e293b.
 * Hover: very dark bases (L < 0.05) lighten slightly; others darken slightly.
 */
export function applySidebarTheme(hex) {
  const L         = hexLuminance(hex)
  const textColor = L < 0.179 ? '#ffffff' : '#1e293b'
  const hoverBg   = L < 0.05 ? mixHex(hex, '#fff', 85) : mixHex(hex, '#000', 85)

  const root = document.documentElement
  root.style.setProperty('--sidebar-accent-color', hex)
  root.style.setProperty('--sidebar-text-color',   textColor)
  root.style.setProperty('--sidebar-hover-bg',     hoverBg)
}
