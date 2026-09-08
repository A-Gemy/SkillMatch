import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { store } from '@/actions/App/Http/Controllers/Auth/PhoneOnboardingController';
import { verifyOtp } from '@/actions/App/Http/Controllers/Auth/AuthController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard, logout } from '@/routes';

function errorMessage(error: unknown): string {
    if (error instanceof HttpResponseError) {
        const body: unknown = error.response.data;
        if (body && typeof body === 'object' && 'message' in body && typeof body.message === 'string') {
            return body.message;
        }
    }
    return 'Unable to complete the request. Please try again.';
}

function VerifyPhone({ phone, onChangePhone }: { phone: string; onChangePhone: () => void }) {
    const form = useHttp({ phone, otp: '' });
    const resend = useHttp({ phone });
    const [message, setMessage] = useState('');
    const [notice, setNotice] = useState('');
    const processing = form.processing || resend.processing;

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (processing) return;
        setMessage('');
        if (! /^[0-9]{6}$/.test(form.data.otp)) {
            form.setError('otp', 'Enter the six-digit verification code.');
            return;
        }
        try {
            await form.post(verifyOtp.url());
            router.visit(dashboard(), { replace: true });
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
            setNotice('A new verification code has been sent.');
        } catch (error) {
            setMessage(errorMessage(error));
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <fieldset disabled={processing} className="grid gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="otp">Verification code</Label>
                    <Input id="otp" name="otp" type="text" inputMode="numeric" autoComplete="one-time-code"
                        pattern="[0-9]{6}" minLength={6} maxLength={6} required autoFocus placeholder="123456"
                        value={form.data.otp} onChange={(event) => form.setData('otp', event.target.value.replace(/\D/g, '').slice(0, 6))} />
                    <InputError message={form.errors.otp} />
                    <InputError message={form.errors.phone ?? resend.errors.phone} />
                </div>
                {message && <InputError message={message} role="alert" />}
                {notice && <p className="text-muted-foreground text-sm" role="status">{notice}</p>}
                <Button type="submit" disabled={processing}>{form.processing && <Spinner />}Verify phone number</Button>
                <Button type="button" variant="outline" disabled={processing} onClick={resendCode}>
                    {resend.processing && <Spinner />}Resend code
                </Button>
                <Button type="button" variant="ghost" onClick={onChangePhone}>Change phone number</Button>
            </fieldset>
        </form>
    );
}

export default function PhoneOnboarding({ phone }: { phone: string | null }) {
    const form = useHttp<{ phone: string }, { phone: string }>({ phone: phone ?? '' });
    const [verificationPhone, setVerificationPhone] = useState<string | null>(null);
    const [message, setMessage] = useState('');

    setLayoutProps({
        title: 'Verify your phone number',
        description: verificationPhone
            ? `Enter the six-digit code sent to ${verificationPhone}. It expires in five minutes.`
            : 'Add your phone number to finish setting up your SkillMatch account. We will send a code to your WhatsApp.',
    });

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing) return;
        setMessage('');
        try {
            const response = await form.post(store.url());
            setVerificationPhone(response.phone);
        } catch (error) {
            setMessage(errorMessage(error));
        }
    }

    return (
        <>
            <Head title="Verify phone number" />
            {verificationPhone ? (
                <VerifyPhone phone={verificationPhone} onChangePhone={() => setVerificationPhone(null)} />
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <fieldset disabled={form.processing} className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="phone">Phone number</Label>
                            <Input id="phone" name="phone" type="tel" autoComplete="tel" required autoFocus
                                placeholder="+20 10 1234 5678" value={form.data.phone}
                                onChange={(event) => form.setData('phone', event.target.value)} />
                            <InputError message={form.errors.phone} />
                        </div>
                        {message && <InputError message={message} role="alert" />}
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}Send verification code
                        </Button>
                    </fieldset>
                </form>
            )}
            <Button variant="ghost" asChild><Link href={logout()} as="button">Log out</Link></Button>
        </>
    );
}

PhoneOnboarding.layout = {
    title: 'Verify your phone number',
    description: 'Finish setting up your SkillMatch account.',
};
