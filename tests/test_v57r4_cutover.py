import importlib.util, pathlib, unittest
p=pathlib.Path(__file__).resolve().parents[1]/'bin/guristas-managed-cutover.py'
spec=importlib.util.spec_from_file_location('cutover',p);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Tests(unittest.TestCase):
 def test_roundtrip(self):
  s='MAILTO=\n'+m.BEGIN+'\n'+'\n'.join(m.LEGACY)+'\n'+m.END+'\n'
  self.assertEqual(m.mode(s)[0],'legacy')
  new=m.replace(s,'legacy','managed')
  self.assertEqual(m.mode(new)[0],'managed')
  self.assertEqual(m.replace(new,'managed','legacy'),s)
 def test_malformed_fail_closed(self):
  s=m.MBEGIN+'\n'+m.DISPATCH+'\n'+m.DISPATCH+'\n'+m.MEND+'\n'
  with self.assertRaises(RuntimeError):m.mode(s)
 def test_preserves_memory_limits_and_times(self):
  self.assertIn('memory_limit=512M',m.LEGACY[-1])
  self.assertIn('10 9 * * *',m.LEGACY[8]);self.assertIn('25 9 * * *',m.LEGACY[9])
 def test_no_duplicate_job(self):self.assertEqual(len(set(m.LEGACY)),11)
if __name__=='__main__':unittest.main(verbosity=2)
