"""Per-network parameters for MWEBscan (Litecoin mainnet / testnet).

Selected by the environment variable MWEBSCAN_NETWORK. Default 'mainnet', so an
unset (or empty) variable reproduces the historical hardcoded mainnet build.

Only network-specific values live here. MWEB protocol constants that are the same
on both networks (witness versions WITVER_PEGIN=9 / WITVER_HOGADDR=8, the
NODE_NETWORK service bit, PROTOCOL_VERSION) stay as shared constants in their own
files.

Testnet values were taken from litecoin-project/litecoin src/chainparams.cpp
(CTestNetParams); the MWEB activation height and the P2SH prefix are confirmed
against a live testnet node (getdeploymentinfo). See the per-value notes.
"""

import os

# consensus.powLimit (00000fff...ffff) is identical on mainnet and testnet, and
# its strict compact nBits form is 0x1e0fffff on both. (The 0x1e0ffff0 sometimes
# quoted is the genesis-block nBits literal, not the compaction of powLimit.)
# Testnet allows min-difficulty blocks, but those headers still satisfy
# target <= powLimit, so the check_pow floor uses the same value on both.

NETWORKS = {
    'mainnet': {
        'name': 'mainnet',
        'MAGIC': b'\xfb\xc0\xb6\xdb',          # pchMessageStart, wire order
        'DEFAULT_PORT': 9333,
        'POW_LIMIT_BITS': 0x1e0fffff,
        'P2PKH_VERSION': 0x30,                 # 'L...'
        'P2SH_VERSION': 0x32,                  # 'M...'
        'BECH32_HRP': 'ltc',
        'DNS_SEEDS': [
            'seed-a.litecoin.loshan.co.uk',
            'dnsseed.thrasher.io',
            'dnsseed.litecointools.com',
            'dnsseed.litecoinpool.org',
            'dnsseed.koin-project.com',
        ],
        'ELECTRUM_SERVERS': [
            ('electrum-ltc.bysh.me', 50002),
            ('electrum.ltc.xurious.com', 50002),
            ('ltc.rentonisk.com', 50002),
            ('backup.electrum-ltc.org', 443),
        ],
        'MWEB_ACTIVATION_HEIGHT': 2265950,     # MWEB activated at 2265984; margin
        'RPC_URL': 'http://127.0.0.1:9332/',   # default; overridden by LTC_RPC_URL
        'DB_FILENAME': 'mwebscan.db',
    },
    'testnet': {
        'name': 'testnet',
        # Verified against chainparams.cpp CTestNetParams + an independent impl.
        'MAGIC': b'\xfd\xd2\xc8\xf1',          # wire order fd d2 c8 f1
        'DEFAULT_PORT': 19335,
        'POW_LIMIT_BITS': 0x1e0fffff,          # same powLimit as mainnet
        'P2PKH_VERSION': 0x6f,                 # 'm'/'n'
        # 0x3a -> 'Q...': the prefix Litecoin Core's encoder emits for testnet
        # P2SH (confirmed by release-notes-0.14.2, "M on mainnet and Q on
        # testnet"). The legacy 0xc4 '2...' (scripthash2) is still decodable but
        # not emitted, so addresses written in that older form aren't matched yet.
        'P2SH_VERSION': 0x3a,
        'BECH32_HRP': 'tltc',
        'DNS_SEEDS': [
            'testnet-seed.litecointools.com',
            'seed-b.litecoin.loshan.co.uk',
            'dnsseed-testnet.thrasher.io',
        ],
        # Only two community testnet servers exist and both are often offline or
        # stuck on an outdated chain. Run your own electrs -testnet if you can.
        # TODO: confirm reachability before relying on these.
        'ELECTRUM_SERVERS': [
            ('electrum-ltc.bysh.me', 51002),
            ('electrum.ltc.xurious.com', 51002),
        ],
        # MWEB activated on testnet at block 2215584 (confirmed on a live node via
        # `litecoin-cli -testnet getdeploymentinfo`: deployments.mweb is a BIP8
        # deployment, status active, since=2215584; start_height 2209536, so
        # lock-in landed in the third 2016-block signaling window, not the first).
        'MWEB_ACTIVATION_HEIGHT': 2215584,
        'RPC_URL': 'http://127.0.0.1:19332/',
        'DB_FILENAME': 'mwebscan-testnet.db',
    },
}


def active_network_name():
    """Selected network; defaults to 'mainnet' when MWEBSCAN_NETWORK is unset/empty."""
    name = (os.environ.get('MWEBSCAN_NETWORK') or 'mainnet').strip().lower()
    if name not in NETWORKS:
        raise SystemExit(
            'MWEBSCAN_NETWORK must be one of %s (got %r)'
            % (', '.join(sorted(NETWORKS)), name))
    return name


NETWORK = active_network_name()
PARAMS = NETWORKS[NETWORK]
