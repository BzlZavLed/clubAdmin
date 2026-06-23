#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUBY_BIN="${RUBY_BIN:-/usr/local/opt/ruby/bin/ruby}"
GEM_HOME_DIR="${ROOT_DIR}/.ruby-gems"

if [[ ! -x "${GEM_HOME_DIR}/bin/pod" ]]; then
  "${ROOT_DIR}/scripts/setup-ios-gems.sh"
fi

export GEM_HOME="${GEM_HOME_DIR}"
export GEM_PATH="${GEM_HOME_DIR}"
export RUBYLIB="${ROOT_DIR}/scripts/ruby-shims${RUBYLIB:+:${RUBYLIB}}"
export PATH="${GEM_HOME_DIR}/bin:$(dirname "${RUBY_BIN}"):${PATH}"

if [[ -z "${DEVELOPER_DIR:-}" ]]; then
  for candidate in /Applications/Xcode.app /Applications/Xcode-beta.app; do
    if [[ -d "${candidate}/Contents/Developer" ]]; then
      export DEVELOPER_DIR="${candidate}/Contents/Developer"
      break
    fi
  done
fi

cd "${ROOT_DIR}"
npm run build
npx cap copy ios
bash "${ROOT_DIR}/scripts/pod-local.sh" install
