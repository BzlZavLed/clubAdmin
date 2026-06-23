#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUBY_BIN="${RUBY_BIN:-/usr/local/opt/ruby/bin/ruby}"
GEM_BIN="${GEM_BIN:-/usr/local/opt/ruby/bin/gem}"
GEM_HOME_DIR="${ROOT_DIR}/.ruby-gems"

if [[ ! -x "${RUBY_BIN}" ]]; then
  echo "Ruby not found at ${RUBY_BIN}. Set RUBY_BIN to a Homebrew Ruby path." >&2
  exit 1
fi

mkdir -p "${GEM_HOME_DIR}/bin"

export GEM_HOME="${GEM_HOME_DIR}"
export GEM_PATH="${GEM_HOME_DIR}"
export PATH="${GEM_HOME_DIR}/bin:$(dirname "${RUBY_BIN}"):${PATH}"

"${GEM_BIN}" install base64 bundler cocoapods -N \
  --install-dir "${GEM_HOME_DIR}" \
  --bindir "${GEM_HOME_DIR}/bin"

"${GEM_HOME_DIR}/bin/pod" --version
