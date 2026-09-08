import { usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { redirect } from '@/actions/App/Http/Controllers/Auth/GoogleAuthController';

export default function GoogleLoginButton() {
    const { errors } = usePage().props;

    return (
        <div className="grid gap-2">
            <Button variant="outline" className="w-full" asChild>
                <a href={redirect.url()}>Continue with Google</a>
            </Button>
            <InputError message={errors.google} role="alert" />
        </div>
    );
}
