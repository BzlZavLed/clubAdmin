# Compatibility shim for gems that still require Ruby's removed kconv stdlib.
# CFPropertyList requires this file but does not use Kconv APIs in our CocoaPods path.
module Kconv
end
