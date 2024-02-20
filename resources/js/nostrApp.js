import {ndkInstance} from "./ndk/instance.js";

export default (livewireComponent) => ({

    async initNDK() {
        const signer = await ndkInstance(this).init();

        signer.user().then(async (user) => {
            console.log('##### user #####', user);
            await livewireComponent.call('loginNostr', user.npub);
        });

    },


});
