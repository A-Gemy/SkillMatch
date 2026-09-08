import { HttpResponseError } from '@inertiajs/core';
import { Head, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import {
    resetPassword,
    store,
    verifyOtp,
} from '@/actions/App/Http/Controllers/Auth/PhonePasswordResetController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

type Step =
    | { name: 'phone' }
    | { name: 'otp'; phone: string }
    | { name: 'password'; phone: string; token: string }
    | { name: 'success' };

function errorMessage(error: unknown): string {
    if (error instanceof HttpResponseError) {
        const body: unknown = error.response.data;
        if (
            error.response.status === 422 &&
            body &&
            typeof body === 'object' &&
            'errors' in body
        ) {
            return '';
        }
        if (
            body &&
            typeof body === 'object' &&
            'message' in body &&
            typeof body.message === 'string'
        ) {
            return body.message;
        }
    }
    return 'Unable to complete the request. Please try again.';
}

function RequestCode({ onSent }: { onSent: (phone: string) => void }) {
    const form = useHttp({ phone: '' });
    const [message, setMessage] = useState('');

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing) return;
        setMessage('');
        try {
            await form.post(store.url());
            onSent(form.data.phone);
        } catch (error) {
            setMessage(errorMessage(error));
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <fieldset disabled={form.processing} className="grid gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="phone">Phone number</Label>
                    <Input
                        id="phone"
                        name="phone"
                        type="tel"
                        autoComplete="tel"
                        autoFocus
                        required
                        placeholder="+20 10 1234 5678"
                        value={form.data.phone}
                        onChange={(event) =>
                            form.setData('phone', event.target.value)
                        }
                    />
                    <InputError message={form.errors.phone} />
                </div>
                {message && <InputError message={message} role="alert" />}
                <Button
                    type="submit"
                    className="w-full"
                    disabled={form.processing}
                >
                    {form.processing && <Spinner />}
                    Send verification code
                </Button>
            </fieldset>
        </form>
    );
}

function VerifyCode({
    phone,
    onVerified,
    onRestart,
}: {
    phone: string;
    onVerified: (token: string) => void;
    onRestart: () => void;
}) {
    const form = useHttp<
        { phone: string; otp: string },
        { reset_token: string }
    >({ phone, otp: '' });
    const resend = useHttp({ phone });
    const [message, setMessage] = useState('');
    const [notice, setNotice] = useState('');
    const processing = form.processing || resend.processing;

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (processing) return;
        setMessage('');
        setNotice('');
        if (!/^[0-9]{6}$/.test(form.data.otp)) {
            form.setError('otp', 'Enter the six-digit verification code.');
            return;
        }
        try {
            const response = await form.post(verifyOtp.url());
            if (
                typeof response?.reset_token !== 'string' ||
                !response.reset_token.trim()
            ) {
                setMessage(
                    'Unable to start your password reset session. Please request a new code.',
                );
                return;
            }
            onVerified(response.reset_token);
        } catch (error) {
            setMessage(errorMessage(error));
        }
    }

    async function resendCode() {
        if (processing) return;
        setMessage('');
        setNotice('');
        try {
            await resend.post(store.url());
            form.resetAndClearErrors('otp');
            setNotice('A new code has been sent. Use it within five minutes.');
        } catch (error) {
            setMessage(errorMessage(error));
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <fieldset disabled={processing} className="grid gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="otp">Verification code</Label>
                    <Input
                        id="otp"
                        name="otp"
                        type="text"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]{6}"
                        minLength={6}
                        maxLength={6}
                        required
                        autoFocus
                        placeholder="123456"
                        value={form.data.otp}
                        onChange={(event) => {
                            form.setData(
                                'otp',
                                event.target.value
                                    .replace(/\D/g, '')
                                    .slice(0, 6),
                            );
                            form.clearErrors('otp');
                        }}
                    />
                    <InputError message={form.errors.otp} />
                    <InputError message={form.errors.phone} />
                    <InputError message={resend.errors.phone} />
                </div>
                {message && <InputError message={message} role="alert" />}
                {notice && (
                    <p className="text-muted-foreground text-sm" role="status">
                        {notice}
                    </p>
                )}
                <Button type="submit" className="w-full" disabled={processing}>
                    {form.processing && <Spinner />}
                    Verify code
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={resendCode}
                >
                    {resend.processing && <Spinner />}
                    Resend code
                </Button>
                <Button type="button" variant="ghost" onClick={onRestart}>
                    Use a different phone number
                </Button>
            </fieldset>
        </form>
    );
}

function NewPassword({
    phone,
    token,
    passwordRules,
    onRestart,
    onSuccess,
}: {
    phone: string;
    token: string;
    passwordRules: string;
    onRestart: () => void;
    onSuccess: () => void;
}) {
    const form = useHttp({
        phone,
        reset_token: token,
        password: '',
        password_confirmation: '',
    });
    const [message, setMessage] = useState('');

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing) return;
        setMessage('');
        form.clearErrors('password_confirmation');
        if (form.data.password !== form.data.password_confirmation) {
            form.setError(
                'password_confirmation',
                'The passwords do not match.',
            );
            return;
        }
        try {
            await form.post(resetPassword.url());
            form.setData({
                phone: '',
                reset_token: '',
                password: '',
                password_confirmation: '',
            });
            onSuccess();
        } catch (error) {
            form.reset('password', 'password_confirmation');
            setMessage(errorMessage(error));
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <fieldset disabled={form.processing} className="grid gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="password">New password</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        required
                        autoFocus
                        placeholder="New password"
                        passwordrules={passwordRules}
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.target.value)
                        }
                    />
                    <InputError message={form.errors.password} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">
                        Confirm password
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        placeholder="Confirm password"
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData(
                                'password_confirmation',
                                event.target.value,
                            )
                        }
                    />
                    <InputError message={form.errors.password_confirmation} />
                </div>
                <InputError message={form.errors.reset_token} />
                <InputError message={form.errors.phone} />
                {message && <InputError message={message} role="alert" />}
                <Button
                    type="submit"
                    className="w-full"
                    disabled={form.processing}
                >
                    {form.processing && <Spinner />}
                    Reset password
                </Button>
                <Button type="button" variant="ghost" onClick={onRestart}>
                    Request a new code
                </Button>
            </fieldset>
        </form>
    );
}

function ResetSuccess() {
    useEffect(() => {
        const timeout = window.setTimeout(() => {
            router.visit(login(), { replace: true });
        }, 1500);

        return () => window.clearTimeout(timeout);
    }, []);

    return (
        <p
            className="text-center text-sm font-medium text-green-600"
            role="status"
        >
            Your password has been reset successfully. Redirecting to login…
        </p>
    );
}

export default function ForgotPassword({
    passwordRules,
}: {
    passwordRules: string;
}) {
    const [step, setStep] = useState<Step>({ name: 'phone' });
    const restart = () => setStep({ name: 'phone' });

    setLayoutProps({
        title:
            step.name === 'phone'
                ? 'Forgot password'
                : step.name === 'otp'
                  ? 'Verify code'
                  : step.name === 'password'
                    ? 'Create new password'
                    : 'Password reset',
        description:
            step.name === 'phone'
                ? 'Enter your phone number and we will send a verification code to your WhatsApp.'
                : step.name === 'otp'
                  ? `Enter the six-digit code sent to ${step.phone}. It expires in five minutes.`
                  : step.name === 'password'
                    ? 'Choose a new password. Your reset session expires in ten minutes.'
                    : 'You can now log in with your new password.',
    });

    return (
        <>
            <Head title="Forgot password" />
            <div className="space-y-6">
                {step.name === 'phone' && (
                    <RequestCode
                        onSent={(phone) => setStep({ name: 'otp', phone })}
                    />
                )}
                {step.name === 'otp' && (
                    <VerifyCode
                        phone={step.phone}
                        onRestart={restart}
                        onVerified={(token) =>
                            setStep({
                                name: 'password',
                                phone: step.phone,
                                token,
                            })
                        }
                    />
                )}
                {step.name === 'password' && (
                    <NewPassword
                        phone={step.phone}
                        token={step.token}
                        passwordRules={passwordRules}
                        onRestart={restart}
                        onSuccess={() => setStep({ name: 'success' })}
                    />
                )}
                {step.name === 'success' && <ResetSuccess />}
                <div className="text-muted-foreground space-x-1 text-center text-sm">
                    <TextLink href={login()}>Back to login</TextLink>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Forgot password',
    description:
        'Enter your phone number and we will send a verification code to your WhatsApp.',
};
