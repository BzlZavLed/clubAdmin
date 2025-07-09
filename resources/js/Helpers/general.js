// File: Helpers/general.js
export const formatDate = (isoString) => {
  return new Date(isoString).toLocaleDateString()
}

export const isEmpty = (value) => {
  return value == null || value === ''
}

export const formatPhoneNumber = (value) => {
  const digits = value.replace(/\D/g, '').substring(0, 10)
  const parts = []

  if (digits.length > 0) parts.push('(' + digits.substring(0, 3))
  if (digits.length >= 4) parts.push(') ' + digits.substring(3, 6))
  if (digits.length >= 7) parts.push(' ' + digits.substring(6, 10))

  return parts.join('')
}

export const forceLogout = () => {
  if (typeof window !== 'undefined') {
    window.location.href = '/force-logout'
  }
}

export const refreshPage = () => {
  if (typeof window !== 'undefined') {
    window.location.reload()
  }
}
