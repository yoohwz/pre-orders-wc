#!/usr/bin/env python3
"""Regression tests for deterministic release and fail-closed SVN tooling."""

from __future__ import annotations

import hashlib
import json
import shutil
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FULL_SHA = "a" * 40


def run(*arguments: str, cwd: Path | None = None, expect_success: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        list(arguments),
        cwd=str(cwd or ROOT),
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if expect_success and result.returncode != 0:
        raise AssertionError(f"command failed: {arguments}\nstdout={result.stdout}\nstderr={result.stderr}")
    if not expect_success and result.returncode == 0:
        raise AssertionError(f"command unexpectedly succeeded: {arguments}\nstdout={result.stdout}")
    return result


CURRENT_VERSION = json.loads(run("python3", "bin/validate-release.py", "metadata", "--source", ".").stdout)["version"]


class ReleasePackageTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="po-release-tests-")
        self.temp = Path(self.temporary.name)

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def build(self, output: Path) -> tuple[Path, Path]:
        run(
            "bash",
            "bin/build-release.sh",
            "--source",
            ".",
            "--output-dir",
            str(output),
            "--version",
            CURRENT_VERSION,
            "--source-sha",
            FULL_SHA,
            "--preparation-run-id",
            "local",
        )
        return output / f"pre-orders-wc-{CURRENT_VERSION}.zip", output / "release-manifest.json"

    def test_build_is_byte_deterministic_and_exact(self) -> None:
        first_package, first_manifest = self.build(self.temp / "first")
        second_package, second_manifest = self.build(self.temp / "second")
        self.assertEqual(first_package.read_bytes(), second_package.read_bytes())
        self.assertEqual(first_manifest.read_bytes(), second_manifest.read_bytes())

        manifest = json.loads(first_manifest.read_text(encoding="utf-8"))
        self.assertEqual(len(manifest["files"]), manifest["file_count"])
        self.assertRegex(manifest["release_control"]["bundle_sha256"], r"^[0-9a-f]{64}$")
        component_paths = {item["path"] for item in manifest["release_control"]["components"]}
        self.assertEqual(
            {
                ".github/workflows/release-prepare.yml",
                "bin/build-release.sh",
                "bin/validate-plugin-check.py",
                "bin/verify-release-ci.sh",
                "bin/validate-release.py",
                "release/payload-manifest.txt",
                "tests/ci/plugin-check-baseline.json",
            },
            component_paths,
        )
        paths = {item["path"] for item in manifest["files"]}
        self.assertEqual(len(paths), manifest["file_count"])
        self.assertIn("pre-orders-wc.php", paths)
        self.assertIn("inc/cores/compatibility.php", paths)
        self.assertIn("img/mixed-preorder.svg", paths)
        self.assertNotIn("AGENTS.md", paths)
        self.assertFalse(any(path.startswith(("tests/", ".github/", "vendor/", "release/")) for path in paths))

        with zipfile.ZipFile(first_package) as archive:
            self.assertEqual(manifest["file_count"], len(archive.namelist()))
            self.assertEqual(
                {f"pre-orders-wc/{path}" for path in paths},
                set(archive.namelist()),
            )

    def test_current_release_requires_exact_changelog_versions(self) -> None:
        for relative, field in (
            ("changelog.txt", "changelog_version"),
            ("readme.txt", "readme_changelog_version"),
        ):
            with self.subTest(relative=relative):
                source = self.temp / field
                shutil.copytree(ROOT, source, ignore=shutil.ignore_patterns(".git", "vendor", "__pycache__"))
                target = source / relative
                target.write_text(
                    target.read_text(encoding="utf-8").replace(f"= {CURRENT_VERSION} (", "= 9000.1 (", 1),
                    encoding="utf-8",
                )
                result = run(
                    "python3",
                    "bin/validate-release.py",
                    "metadata",
                    "--source",
                    str(source),
                    "--version",
                    CURRENT_VERSION,
                    expect_success=False,
                )
                self.assertIn(f"{field} must exactly equal active release version {CURRENT_VERSION}", result.stderr)

    def test_package_tampering_fails_closed(self) -> None:
        package, manifest = self.build(self.temp / "tamper")
        with package.open("ab") as handle:
            handle.write(b"tampered")
        run(
            "python3",
            "bin/validate-release.py",
            "validate-package",
            "--package",
            str(package),
            "--manifest",
            str(manifest),
            expect_success=False,
        )

    def test_symlink_payload_is_rejected(self) -> None:
        source = self.temp / "candidate"
        shutil.copytree(ROOT, source, ignore=shutil.ignore_patterns(".git", "vendor"))
        (source / "css" / "frontend.css").unlink()
        (source / "css" / "frontend.css").symlink_to(source / "readme.txt")
        run(
            "bash",
            "bin/build-release.sh",
            "--source",
            str(source),
            "--output-dir",
            str(self.temp / "symlink-output"),
            "--version",
            CURRENT_VERSION,
            "--source-sha",
            FULL_SHA,
            expect_success=False,
        )

    def test_release_control_binding_rejects_self_consistent_stale_contract(self) -> None:
        package, manifest_path = self.build(self.temp / "control-binding")
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        preparation_path = self.temp / "preparation-record.json"
        preparation = {
            "manifest_sha256": manifest["manifest_sha256"],
            "release_control": manifest["release_control"],
        }
        preparation_path.write_text(json.dumps(preparation), encoding="utf-8")
        run(
            "python3",
            "bin/validate-release.py",
            "validate-control-binding",
            "--manifest",
            str(manifest_path),
            "--preparation-record",
            str(preparation_path),
        )

        stale_control = manifest["release_control"]
        stale_control["components"][0]["sha256"] = "0" * 64
        stale_identity = {"schema_version": stale_control["schema_version"], "components": stale_control["components"]}
        stale_control["bundle_sha256"] = hashlib.sha256(
            json.dumps(stale_identity, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        manifest["release_control"] = stale_control
        unsigned = dict(manifest)
        unsigned.pop("manifest_sha256")
        manifest["manifest_sha256"] = hashlib.sha256(
            json.dumps(unsigned, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        manifest_path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        preparation["manifest_sha256"] = manifest["manifest_sha256"]
        preparation["release_control"] = stale_control
        preparation_path.write_text(json.dumps(preparation), encoding="utf-8")
        run(
            "python3",
            "bin/validate-release.py",
            "validate-package",
            "--package",
            str(package),
            "--manifest",
            str(manifest_path),
        )
        run(
            "python3",
            "bin/validate-release.py",
            "validate-control-binding",
            "--manifest",
            str(manifest_path),
            "--preparation-record",
            str(preparation_path),
            expect_success=False,
        )

    def test_future_release_requires_exact_version_strings(self) -> None:
        source = self.temp / "future-candidate"
        shutil.copytree(ROOT, source, ignore=shutil.ignore_patterns(".git", "__pycache__"))
        major = CURRENT_VERSION.split(".")[0]
        future = f"{int(major) + 1}.0.0"
        replacements = {
            "pre-orders-wc.php": f"Version: {CURRENT_VERSION}",
            "readme.txt": f"Stable tag: {CURRENT_VERSION}",
            "changelog.txt": f"= {CURRENT_VERSION} (",
            "languages/pre-orders-wc.pot": f"Pre-Orders for WooCommerce {CURRENT_VERSION}",
        }
        for relative, before in replacements.items():
            target = source / relative
            content = target.read_text(encoding="utf-8")
            if relative == "readme.txt":
                content = content.replace(f"= {CURRENT_VERSION} (", f"= {future} (", 1)
                content += f"\n== Upgrade Notice ==\n\n= {future} =\nFixture notice.\n"
            target.write_text(content.replace(before, before.replace(CURRENT_VERSION, future), 1), encoding="utf-8")
        run("python3", "bin/validate-release.py", "metadata", "--source", str(source), "--version", future)
        readme = source / "readme.txt"
        exact = readme.read_text(encoding="utf-8")
        readme.write_text(exact.replace(f"= {future} =", f"= {CURRENT_VERSION} =", 1), encoding="utf-8")
        result = run("python3", "bin/validate-release.py", "metadata", "--source", str(source), "--version", future, expect_success=False)
        self.assertIn("upgrade_notice_version", result.stderr)

    def test_public_zip_requires_exact_tree_without_extracting(self) -> None:
        package, manifest = self.build(self.temp / "public-source")
        public_zip = self.temp / "public.zip"
        command = (
            "python3", "bin/validate-release.py", "validate-public-package",
            "--package", str(public_zip), "--manifest", str(manifest),
        )

        def write_public_zip(extra: str | None = None, tamper: bool = False) -> None:
            with zipfile.ZipFile(package) as source, zipfile.ZipFile(public_zip, "w") as public:
                public.writestr("pre-orders-wc/", "")
                for member in source.infolist():
                    data = source.read(member)
                    if tamper and member.filename.endswith("/readme.txt"):
                        data = b"x" + data[1:]
                    public.writestr(member, data)
                if extra:
                    public.writestr(extra, b"unexpected")

        write_public_zip()
        run(*command)
        for name in ("../extra.php", "pre-orders-wc/extra.php", "pre-orders-wc/./license.txt"):
            write_public_zip(extra=name)
            run(*command, expect_success=False)
        write_public_zip(tamper=True)
        run(*command, expect_success=False)


@unittest.skipUnless(shutil.which("svn") and shutil.which("svnadmin"), "Subversion CLI is not available")
class LocalSvnStateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="po-svn-tests-")
        self.temp = Path(self.temporary.name)
        self.repository = self.temp / "repository"
        self.import_tree = self.temp / "import"
        (self.import_tree / "trunk").mkdir(parents=True)
        (self.import_tree / "tags").mkdir()
        (self.import_tree / "assets").mkdir()
        (self.import_tree / "trunk" / "old.php").write_text("old\n", encoding="utf-8")
        (self.import_tree / "assets" / "icon-128x128.png").write_bytes(b"asset-v1")
        run("svnadmin", "create", str(self.repository))
        self.svn_url = self.repository.as_uri()
        run("svn", "import", "--quiet", str(self.import_tree), self.svn_url, "-m", "initial fixture")
        self.working_copy = self.temp / "working-copy"
        run("svn", "checkout", "--quiet", self.svn_url, str(self.working_copy))
        self.policy = self.temp / "policy.json"
        self.policy.write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "slug": "pre-orders-wc",
                    "svn_url": self.svn_url,
                    "assets_mode": "unchanged",
                    "release_confirmation": {"mode": "unknown", "observed_at": None, "source": "test"},
                }
            ),
            encoding="utf-8",
        )
        self.build = self.temp / "build"
        run(
            "bash",
            "bin/build-release.sh",
            "--source",
            ".",
            "--output-dir",
            str(self.build),
            "--version",
            CURRENT_VERSION,
            "--source-sha",
            FULL_SHA,
        )

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def test_stage_is_local_only_and_final_recheck_detects_relevant_drift(self) -> None:
        preflight = self.temp / "preflight.json"
        run(
            "python3",
            "bin/wporg-release.py",
            "stage-svn",
            "--working-copy",
            str(self.working_copy),
            "--policy",
            str(self.policy),
            "--version",
            CURRENT_VERSION,
            "--package",
            str(self.build / f"pre-orders-wc-{CURRENT_VERSION}.zip"),
            "--manifest",
            str(self.build / "release-manifest.json"),
            "--output",
            str(preflight),
        )
        remote_check = self.temp / "remote-check"
        run("svn", "checkout", "--quiet", self.svn_url, str(remote_check))
        self.assertFalse((remote_check / "tags" / CURRENT_VERSION).exists(), "local staging must not mutate remote SVN")
        run(
            "python3",
            "bin/wporg-release.py",
            "compare-snapshot",
            "--working-copy",
            str(remote_check),
            "--policy",
            str(self.policy),
            "--version",
            CURRENT_VERSION,
            "--snapshot",
            str(preflight),
        )

        (remote_check / "assets" / "icon-128x128.png").write_bytes(b"asset-v2")
        run("svn", "commit", "--quiet", str(remote_check / "assets"), "-m", "asset drift")
        drifted = self.temp / "drifted"
        run("svn", "checkout", "--quiet", self.svn_url, str(drifted))
        run(
            "python3",
            "bin/wporg-release.py",
            "compare-snapshot",
            "--working-copy",
            str(drifted),
            "--policy",
            str(self.policy),
            "--version",
            CURRENT_VERSION,
            "--snapshot",
            str(preflight),
            expect_success=False,
        )

    def test_existing_higher_svn_version_blocks_a_downgrade(self) -> None:
        newer = f"{int(CURRENT_VERSION.split('.')[0]) + 1}.0.0"
        run("svn", "mkdir", f"{self.svn_url}/tags/{newer}", "-m", "newer version fixture")
        run("svn", "update", "--quiet", str(self.working_copy))
        result = run(
            "python3", "bin/wporg-release.py", "stage-svn",
            "--working-copy", str(self.working_copy), "--policy", str(self.policy),
            "--version", CURRENT_VERSION,
            "--package", str(self.build / f"pre-orders-wc-{CURRENT_VERSION}.zip"),
            "--manifest", str(self.build / "release-manifest.json"),
            "--output", str(self.temp / "blocked.json"), expect_success=False,
        )
        self.assertIn(f"requested version must be greater than existing SVN tag {newer}", result.stderr)
        self.assertEqual("", run("svn", "status", str(self.working_copy)).stdout)

    def test_ambiguous_commit_can_be_verified_from_exact_svn_log_and_trees(self) -> None:
        preflight = self.temp / "preflight.json"
        run(
            "python3",
            "bin/wporg-release.py",
            "stage-svn",
            "--working-copy",
            str(self.working_copy),
            "--policy",
            str(self.policy),
            "--version",
            CURRENT_VERSION,
            "--package",
            str(self.build / f"pre-orders-wc-{CURRENT_VERSION}.zip"),
            "--manifest",
            str(self.build / "release-manifest.json"),
            "--output",
            str(preflight),
        )
        manifest = json.loads((self.build / "release-manifest.json").read_text(encoding="utf-8"))
        publish_run_id = "12345"
        message = (
            f"Release {CURRENT_VERSION} from Git {FULL_SHA}; manifest {manifest['manifest_sha256']}; "
            f"GitHub run https://github.com/yoohwz/pre-orders-wc/actions/runs/{publish_run_id}"
        )
        run(
            "svn",
            "commit",
            "--quiet",
            str(self.working_copy / "trunk"),
            str(self.working_copy / "tags" / CURRENT_VERSION),
            "-m",
            message,
        )
        verified_copy = self.temp / "verified-copy"
        run("svn", "checkout", "--quiet", self.svn_url, str(verified_copy))
        verification = self.temp / "svn-verification.json"
        verify_command = (
            "python3", "bin/wporg-release.py", "verify-svn-publication",
            "--working-copy", str(verified_copy), "--policy", str(self.policy),
            "--version", CURRENT_VERSION, "--candidate-sha", FULL_SHA,
            "--manifest", str(self.build / "release-manifest.json"),
            "--preflight-record", str(preflight), "--output", str(verification),
        )
        run(*verify_command, "--publish-run-id", publish_run_id)
        record = json.loads(verification.read_text(encoding="utf-8"))
        self.assertEqual("SVN_COMMITTED_VERIFIED", record["status"])
        self.assertEqual(manifest["tree_sha256"], record["tag"]["tree_sha256"])
        run(*verify_command, "--publish-run-id", publish_run_id, "--expected-revision", "999", expect_success=False)
        result = run(*verify_command, "--publish-run-id", publish_run_id[:-1], expect_success=False)
        self.assertIn("release log identity mismatch", result.stderr)



if __name__ == "__main__":
    unittest.main()
