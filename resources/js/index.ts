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
    serverDid,
    signOut,
    syncSessions,
    withSession,
} from './reconnect';
export type { ReconnectState, ServerUser } from './reconnect';

export { isLocal, procedure, query, xrpc, XrpcError } from './xrpc';
export type { XrpcOptions } from './xrpc';

export { createRecord, deleteRecord, RecordWriteError } from './records';
export type { WriteOptions } from './records';

export {
    authorLabels,
    badges,
    hiddenFromGuests,
    labelName,
    warnings,
} from './moderation';
export type { Moderation, NamedLabel } from './moderation';

export { authorHandle, authorName, INVALID_HANDLE } from './views';
export type { Author } from './views';

export {
    disablePush,
    enablePush,
    fromBase64url,
    pushState,
    pushSupported,
} from './push';
export type { PushState } from './push';
