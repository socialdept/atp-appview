/**
 * Frontend-mode atproto sessions for apps on socialdept/atp-appview.
 *
 * The browser holds the OAuth tokens and a non-extractable DPoP key, and
 * the server keeps an ordinary session it can rebuild from them at any time.
 */
export { configureAtproto } from './config';
export type { AtprotoConfig, AtprotoRoute } from './config';

export {
    handleCallback,
    PopupBlockedError,
    SignInCanceledError,
    signIn,
    signInPopup,
} from './oauth';
export type { SignIn } from './oauth';

export {
    account,
    activeDid,
    currentAccount,
    currentSession,
    freshSession,
    onSessionChange,
    pdsFetch,
    SessionExpiredError,
    TransientSessionError,
} from './session';
export type {
    AtpAccount,
    AtpSession,
    AtpTokens,
    SessionEvent,
} from './session';

export {
    dismissReconnect,
    onReconnectChange,
    reconcile,
    ReconnectCanceledError,
    reconnected,
    reconnectState,
    signOut,
    syncSessions,
    withSession,
} from './reconnect';
export type { ReconnectState, ServerUser } from './reconnect';
