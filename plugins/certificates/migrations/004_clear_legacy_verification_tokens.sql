UPDATE cert_certificates
SET verification_token = ''
WHERE verification_token IS NOT NULL AND verification_token <> '';
