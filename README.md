# omnibus/canada-post

Canada Post for [glitchr/omnibus](https://github.com/glitchr-studio/omnibus): Get Rates,
non-contract shipments (paid by the card on file) or contract shipments with their labels,
tracking and the nearest post offices - the XML REST web services with basic auth.

```yaml
omnibus:
    gateways:
        canada_post:
            factory: canada_post
            options:
                username: '%env(CANADA_POST_USERNAME)%'    # the API key's username and password
                password: '%env(CANADA_POST_PASSWORD)%'
                customer_number: '%env(CANADA_POST_CUSTOMER)%'
                contract_id: '%env(CANADA_POST_CONTRACT)%' # optional: commercial contract
                sandbox: true
                rates: [...]                               # optional: configured prices instead of Get Rates
```

The service is the service code (DOM.RP Regular, DOM.EP Expedited, DOM.XP Xpresspost, DOM.PC
Priority, USA.EP, USA.XP, INT.XP...). Shipment options: `sender_province` and `recipient_province`
(two letters, Canadian addresses need them), `signature`, `label_format` (PDF, ZPL), `description`
(for customs).

Credentials: a [Canada Post Developer Program](https://www.canadapost-postescanada.ca/information/app/drc/home)
account gives development and production API keys and your customer number; a contract id comes
with a commercial agreement.

Built from Canada Post's published API documentation and tested on recorded answers; not yet run
against the development environment: that needs the credentials above.

License: LGPL-3.0-or-later.
