import excplicitRelays from "./excplicitRelays.js";
import NDK, {NDKNip07Signer} from "@nostr-dev-kit/ndk";
import NDKCacheAdapterDexie from "@nostr-dev-kit/ndk-cache-dexie";

export const ndkInstance = (Alpine) => ({
    async init() {
        const signer = new NDKNip07Signer();
        const dexieAdapter = new NDKCacheAdapterDexie({dbName: 'ptNostrDB', expirationTime: 60 * 60 * 24 * 7})

        try {
            const urls = excplicitRelays.map((relay) => {
                if (relay.startsWith('ws')) {
                    return relay.replace('ws', 'http');
                }
                if (relay.startsWith('wss')) {
                    return relay.replace('wss', 'https');
                }
            });

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort('timeout'), 5000);

            const requests = urls.map((url) =>
                fetch(url, {
                    headers: {Accept: 'application/nostr+json'},
                    signal: controller.signal,
                })
            );
            const responses = await Promise.all(requests);
            const errors = responses.filter((response) => !response.ok);

            if (errors.length > 0) {
                throw errors.map((response) => Error(response.statusText));
            }

            let verifiedRelays = responses.map((res) => {
                if (res.url.startsWith('http')) {
                    return res.url.replace('http', 'ws');
                }
                if (res.url.startsWith('https')) {
                    return res.url.replace('https', 'wss');
                }
            });

            // clear timeout
            clearTimeout(timeoutId);

            console.log('##### verifiedRelays #####', verifiedRelays);
            Alpine.$store.ndk.explicitRelayUrls = verifiedRelays;

            const instance = new NDK({
                explicitRelayUrls: Alpine.$store.ndk.explicitRelayUrls,
                signer: signer,
                cacheAdapter: dexieAdapter,
                outboxRelayUrls: [],
                enableOutboxModel: false,
            });

            try {
                await instance.connect();
            } catch (error) {
                throw new Error('NDK instance init failed: ', error);
            }

            // init nip07 signer and fetch profile
            // await signer.user().then(async (user) => {
            //     if (!!user.npub) {
            //         instance.user = instance.getUser({
            //             npub: user.npub,
            //         });
            //         await instance.user.fetchProfile();
            //     }
            // }).catch((error) => {
            //     console.log('##### nip07 signer error #####', error);
            // });

            return instance;
        } catch (e) {
            console.log(e);
        }
    }
});
