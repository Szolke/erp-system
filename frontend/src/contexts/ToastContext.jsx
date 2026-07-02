import { createContext, useCallback, useContext, useState } from 'react'

const ToastCtx = createContext(null)
let _nextId = 0

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const addToast = useCallback((message, type = 'info') => {
    const id = ++_nextId
    setToasts(prev => [...prev, { id, message, type }])
    setTimeout(() => setToasts(prev => prev.filter(x => x.id !== id)), 4000)
  }, [])

  const remove = useCallback((id) => setToasts(prev => prev.filter(x => x.id !== id)), [])

  return (
    <ToastCtx.Provider value={addToast}>
      {children}
      {toasts.length > 0 && (
        <div className="toast-container">
          {toasts.map(toast => (
            <div key={toast.id} className={`toast toast-${toast.type}`}>
              <span>{toast.message}</span>
              <button className="toast-close" onClick={() => remove(toast.id)}>×</button>
            </div>
          ))}
        </div>
      )}
    </ToastCtx.Provider>
  )
}

export function useToast() {
  const fn = useContext(ToastCtx)
  if (!fn) throw new Error('useToast must be used within ToastProvider')
  return fn
}
